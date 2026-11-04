<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\NotificationCampaign;
use App\Models\PushRegistration;
use App\Services\FirebaseMessagingService;
use App\Services\PushRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class DeliverNotificationPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 45;

    public function __construct(public string $kind, public int $sourceId, public int $registrationId, public string $tokenHash) {}

    public function backoff(): array
    {
        return [60, 120, 300, 900];
    }

    public function handle(FirebaseMessagingService $firebase, PushRecipients $recipients): void
    {
        if (! config('notifications.push_enabled')) {
            return;
        }
        $registration = PushRegistration::whereKey($this->registrationId)->where('token_hash', $this->tokenHash)->first();
        if (! $registration || ! $recipients->eligible($registration)) {
            return;
        }
        $source = $this->kind === 'personal'
            ? Notification::whereKey($this->sourceId)->where('user_id', $registration->user_id)->first()
            : NotificationCampaign::visible()->forRecipient($registration->user)->find($this->sourceId);
        if (! $source || ($this->kind === 'personal' && ($source->read_at || $source->created_at?->lte(now()->subDays(30))))) {
            return;
        }
        $key = 'notification-push:'.$this->kind.':'.$this->sourceId.':'.$this->registrationId.':'.$this->tokenHash;
        if (Cache::has($key.':sent')) {
            return;
        }
        $lock = Cache::lock($key.':lock', 50);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        try {
            if (Cache::has($key.':sent')) {
                return;
            }
            $data = ['kind' => $this->kind, $this->kind === 'personal' ? 'notification_id' : 'campaign_id' => (string) $this->sourceId];
            // The app fetches details using its own authenticated API. Do not expose
            // payment proofs, transfer secrets or arbitrary model data to FCM.
            $firebase->sendToToken($registration->token_ciphertext, $source->title, mb_strcut($source->body, 0, 2000, 'UTF-8'), $data);
            Cache::put($key.':sent', true, now()->addDay());
        } catch (RequestException $error) {
            $status = $error->response->status();
            $details = $error->response->json('error.details') ?? [];
            $unregistered = collect($details)->contains(fn ($detail) => ($detail['@type'] ?? '') === 'type.googleapis.com/google.firebase.fcm.v1.FcmError' && ($detail['errorCode'] ?? '') === 'UNREGISTERED');
            if ($unregistered) {
                PushRegistration::whereKey($this->registrationId)->where('token_hash', $this->tokenHash)->delete();

                return;
            }
            if ($status === 401) {
                $firebase->forgetAccessToken();
                throw new RuntimeException('Firebase authentication must be refreshed.');
            }
            if ($status === 429 || $status >= 500) {
                $retryAfter = $error->response->header('Retry-After');
                if (is_string($retryAfter) && ctype_digit($retryAfter)) {
                    $this->release(max(60, min(3600, (int) $retryAfter)));

                    return;
                }
                // Do not persist the upstream exception: it may contain a token.
                throw new RuntimeException('Firebase temporarily unavailable (HTTP '.$status.').');
            }
            Log::warning('Notification push rejected.', ['kind' => $this->kind, 'source_id' => $this->sourceId, 'http_status' => $status]);
        } catch (InvalidArgumentException) {
            Log::warning('Notification push payload rejected locally.', ['kind' => $this->kind, 'source_id' => $this->sourceId]);
        } catch (Throwable) {
            throw new RuntimeException('Notification push transport or configuration failed.');
        } finally {
            $lock->release();
        }
    }
}
