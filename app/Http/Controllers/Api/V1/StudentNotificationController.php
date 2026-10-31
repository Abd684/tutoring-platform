<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Notification;
use Illuminate\Http\Request;

class StudentNotificationController extends ApiController
{
    public function index(Request $r)
    {
        $p = Notification::where('user_id', $r->user()->id)->orderByDesc('id')->paginate($this->perPage($r));
        $p->setCollection($p->getCollection()->map(fn ($n) => $n->only(['id', 'type', 'title', 'body', 'data', 'created_at', 'related_payment_id']) + ['read' => $n->read_at !== null, 'read_at' => $n->read_at]));

        return $this->paginated($p);
    }

    public function read(Request $r, Notification $notification)
    {
        abort_unless((int) $notification->user_id === (int) $r->user()->id, 404);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return $this->success(['id' => $notification->id, 'read' => true, 'read_at' => $notification->read_at]);
    }
}
