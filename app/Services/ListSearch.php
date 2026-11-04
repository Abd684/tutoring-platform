<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Group;
use App\Models\Individual;
use App\Models\Lesson;
use App\Models\PaidBooking;
use App\Models\TeacherSubject;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Adds constraints to an already authorized query; never creates a visibility scope. */
class ListSearch
{
    private function profile(string $name): array
    {
        $course = ['subject_id' => 'subject_id', 'teacher_id' => 'teacher_id'];
        $group = ['teacher_subject_id' => 'teacher_subject_id', 'subject_id' => 'teacherSubject.subject_id', 'teacher_id' => 'teacherSubject.teacher_id', 'region_id' => 'teacherSubject.teacher.user.region_id', 'governorate_id' => 'teacherSubject.teacher.user.region.governortate_id'];
        $student = ['student_id' => 'student_id', 'region_id' => 'student.user.region_id'];

        return match ($name) {
            'teachers' => [['user.name', 'specialization'], ['subject_id' => 'teacherSubjects.subject_id', 'region_id' => 'user.region_id', 'governorate_id' => 'user.region.governortate_id']],
            'groups' => [['name', 'teacherSubject.subject.name', 'teacherSubject.teacher.user.name'], $group, 'session_price'],
            'own_groups' => [['name', 'teacherSubject.subject.name'], array_diff_key($group, ['teacher_id' => true]), 'session_price'],
            'individuals' => [['student.user.name', 'teacherSubject.subject.name'], $group + $student, 'session_price'],
            'members', 'join_requests' => [['student.user.name'], $student],
            'courses', 'curriculum' => [['subject.name', 'teacher.user.name'], $course, 'price'],
            'own_courses' => [['subject.name'], ['subject_id' => 'subject_id'], 'price'],
            'subjects' => [['name', 'description'], [], null],
            'units' => [['title', 'description'], ['teacher_subject_id' => 'teacher_subject_id'], 'price'],
            'lessons' => [['title', 'description'], ['unit_id' => 'unit_id'], 'price'],
            'contents' => [['title', 'description'], ['lesson_id' => 'lesson_id'], 'price', ['content_type' => ['type', 'video,pdf,quiz']]],
            'assets' => [['content.title'], ['content_id' => 'content_id'], null, ['content_type' => ['content.type', 'video,pdf,quiz']]],
            'quizzes' => [['content.title', 'content.lesson.title', 'content.lesson.unit.teacherSubject.subject.name', 'content.lesson.unit.teacherSubject.teacher.user.name'], ['lesson_id' => 'content.lesson_id', 'teacher_subject_id' => 'content.lesson.unit.teacher_subject_id', 'teacher_id' => 'content.lesson.unit.teacherSubject.teacher_id']],
            'sessions' => [['teacher.user.name'], ['teacher_id' => 'teacher_id'], null],
            'enrollments' => [['teacher.user.name'], ['teacher_id' => 'teacher_id'], null],
            'notifications', 'campaigns', 'feed' => [['title', 'body'], [], null],
            'plans' => [['name'], [], 'price'],
            'subscriptions' => [['plan.name'], [], null],
            'payments' => [['provider_reference'], [], 'amount'],
            'refunds' => [['reason', 'original_transfer_number'], ['payment_id' => 'payment_id'], 'amount'],
            'locations' => [['name'], [], null],
            'availability' => [['date', 'start_time', 'end_time'], [], 'price', ['status' => ['status', 'active,disabled'], 'recurrence_type' => ['recurrence_type', 'none,weekly,custom']]],
            default => throw new \InvalidArgumentException('Unknown search profile: '.$name),
        };
    }

    public function term(Request $request): string
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        // Explicit q takes precedence, including q="" to clear a legacy search.
        return trim($data['q'] ?? (array_key_exists('q', $data) ? '' : ($data['search'] ?? '')));
    }

    public function pattern(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term, 'UTF-8')).'%';
    }

    public function apply($query, Request $request, string $profile)
    {
        [$fields, $ids, $price, $enums] = array_pad($this->profile($profile), 4, null);
        $term = $this->term($request);
        $rules = [];
        foreach ($ids as $key => $path) {
            $rules[$key] = ['sometimes', 'nullable', 'integer', 'min:1'];
        }
        foreach ($enums ?? [] as $key => [$path, $values]) {
            $rules[$key] = ['sometimes', 'nullable', 'in:'.$values];
        }
        if ($price !== null) {
            $rules['min_price'] = ['sometimes', 'nullable', 'numeric', 'between:0,9999999999.99'];
            $rules['max_price'] = ['sometimes', 'nullable', 'numeric', 'between:0,9999999999.99'];
        }
        $data = $request->validate($rules);
        if (in_array($profile, ['sessions', 'availability'], true)) {
            $dates = $request->validate([
                'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'timezone' => ['sometimes', 'timezone'],
            ]);
            if (isset($dates['date_from'], $dates['date_to']) && $dates['date_to'] < $dates['date_from']) {
                throw ValidationException::withMessages(['date_to' => 'The end date must be on or after the start date.']);
            }
            foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
                if (isset($dates[$key])) {
                    if ($profile === 'availability') {
                        $query->whereDate('date', $operator, $dates[$key]);
                    } else {
                        $date = Carbon::parse($dates[$key], $dates['timezone'] ?? 'Asia/Damascus');
                        if ($key === 'date_to') {
                            $date->addDay();
                        }
                        $query->where('starts_at', $key === 'date_to' ? '<' : '>=', $date->setTimezone(config('app.timezone')));
                    }
                }
            }
        }
        if (isset($data['min_price'], $data['max_price']) && $data['min_price'] > $data['max_price']) {
            throw ValidationException::withMessages(['max_price' => 'The maximum price must be at least the minimum price.']);
        }
        foreach ($ids as $key => $path) {
            if (isset($data[$key])) {
                $this->path($query, $path, fn ($q, $column) => $q->where($column, $data[$key]));
            }
        }
        foreach ($enums ?? [] as $key => [$path]) {
            if (isset($data[$key])) {
                $this->path($query, $path, fn ($q, $column) => $q->where($column, $data[$key]));
            }
        }
        foreach (['min_price' => '>=', 'max_price' => '<='] as $key => $operator) {
            if ($price !== null && isset($data[$key])) {
                $this->path($query, $price, fn ($q, $column) => $q->where($column, $operator, $data[$key]));
            }
        }
        if ($term !== '') {
            $pattern = $this->pattern($term);
            $query->where(function ($outer) use ($fields, $pattern, $profile) {
                foreach ($fields as $field) {
                    $outer->orWhere(fn ($q) => $this->path($q, $field, fn ($nested, $column) => $this->like($nested, $column, $pattern)));
                }
                if ($profile === 'curriculum') {
                    foreach (['units' => 'title', 'units.lessons' => 'title', 'units.lessons.contents' => 'title'] as $relation => $column) {
                        $outer->orWhereHas('units', function ($unit) use ($relation, $column, $pattern) {
                            $unit->where('status', 'published');
                            if ($relation === 'units') {
                                $this->like($unit, $unit->qualifyColumn($column), $pattern);
                            } else {
                                $unit->whereHas('lessons', function ($lesson) use ($relation, $column, $pattern) {
                                    $lesson->where('status', 'published');
                                    if ($relation === 'units.lessons') {
                                        $this->like($lesson, $lesson->qualifyColumn($column), $pattern);
                                    } else {
                                        $lesson->whereHas('contents', function ($content) use ($column, $pattern) {
                                            $content->where('status', 'published');
                                            $this->like($content, $content->qualifyColumn($column), $pattern);
                                        });
                                    }
                                });
                            }
                        });
                    }
                }
                if ($profile === 'sessions') {
                    $outer->orWhereHasMorph('target', [Group::class, Individual::class], function ($q, $type) use ($pattern) {
                        $q->where(function ($target) use ($type, $pattern) {
                            $this->path($target, 'teacherSubject.subject.name', fn ($nested, $column) => $this->like($nested, $column, $pattern));
                            $target->orWhere(function ($nested) use ($type, $pattern) {
                                $this->path($nested, $type === Group::class ? 'name' : 'student.user.name', fn ($q, $column) => $this->like($q, $column, $pattern));
                            });
                        });
                    });
                }
                if ($profile === 'enrollments') {
                    $outer->orWhereHasMorph('enrollable', [TeacherSubject::class, Unit::class, Lesson::class, Content::class, PaidBooking::class], function ($q, $type) use ($pattern) {
                        $field = match ($type) {
                            TeacherSubject::class => 'subject.name',
                            PaidBooking::class => 'teacherSubject.subject.name',
                            default => 'title',
                        };
                        $this->path($q, $field, fn ($nested, $column) => $this->like($nested, $column, $pattern));
                    });
                }
            });
        }

        return $query;
    }

    private function path($query, string $path, \Closure $constraint): void
    {
        $last = strrpos($path, '.');
        if ($last === false) {
            $column = method_exists($query, 'qualifyColumn') ? $query->qualifyColumn($path) : $path;
            $constraint($query, $column);
        } else {
            $query->whereHas(substr($path, 0, $last), fn ($q) => $constraint($q, $q->qualifyColumn(substr($path, $last + 1))));
        }
    }

    private function like($query, string $column, string $pattern): void
    {
        // Identifiers only come from the server-side profiles, values are always bound.
        $grammar = $query->getConnection()->getQueryGrammar();
        $query->whereRaw('LOWER('.$grammar->wrap($column).") LIKE ? ESCAPE '!'", [$pattern]);
    }
}
