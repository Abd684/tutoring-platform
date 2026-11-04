<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Preserve session enum/foreign-key fields for existing teacher clients. */
class LegacyTeacherSessions
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (! $request->is('api/v1/teacher/sessions', 'api/v1/teacher/sessions/*', 'api/v1/teacher/v1/sessions/*') || $request->header('X-Liqaa-Teaching-Contract') === '2' || ! $response instanceof JsonResponse || $response->getStatusCode() >= 400) {
            return $response;
        }
        $links = DB::table('legacy_teaching_links')->whereIn('kind', ['session', 'individual'])->get();
        $bySession = [];
        $byIndividual = [];
        foreach ($links as $link) {
            $payload = json_decode($link->payload, true);
            if ($link->kind === 'session') {
                $bySession[$link->source_id] = $payload['enrollment_id'] ?? null;
            } else {
                $byIndividual[$payload['individual_id']] = $link->source_id;
            }
        }
        $walk = function ($value) use (&$walk, $bySession, $byIndividual) {
            if (! is_array($value)) {
                return $value;
            }
            foreach ($value as $k => $v) {
                if (is_array($v)) {
                    $value[$k] = $walk($v);
                }
            }
            if (isset($value['id'],$value['teacher_id'],$value['starts_at'],$value['ends_at'],$value['type_id']) && in_array($value['type'] ?? null, ['group', 'individual'], true)) {
                $individual = $value['type'] === 'individual';
                $value['teaching_type'] = $value['type'];
                $value['type'] = strtoupper($value['type']);
                $value['group_id'] = $individual ? null : $value['type_id'];
                $value['enrollment_id'] = $individual ? ($bySession[$value['id']] ?? $byIndividual[$value['type_id']] ?? null) : null;
            }

return $value;
        };
        $response->setData($walk($response->getData(true)));
        $response->headers->set('Vary', trim(($response->headers->get('Vary') ?? '').', X-Liqaa-Teaching-Contract', ', '));

        return $response;
    }
}
