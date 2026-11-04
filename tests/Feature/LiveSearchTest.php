<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentAsset;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupJoinRequest;
use App\Models\GroupStudent;
use App\Models\Individual;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\NotificationCampaign;
use App\Models\Payment;
use App\Models\Quiz;
use App\Models\RefundRequest;
use App\Models\Region;
use App\Models\Session;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubscriptionPlan;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\TeacherSubscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\ListSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LiveSearchTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name = 'هبة الخطيب'): array
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $user->id, 'specialization' => 'لغة عربية', 'status' => 'active']);
        $subject = Subject::create(['name' => 'اللغة العربية', 'status' => 'active']);
        $course = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'status' => 'active', 'price' => 25000]);
        $unit = Unit::create(['teacher_subject_id' => $course->id, 'title' => 'قواعد النحو', 'status' => 'published', 'price' => 10000]);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'الفعل الماضي', 'status' => 'published', 'price' => 5000]);
        $content = Content::create(['lesson_id' => $lesson->id, 'title' => 'شرح الأفعال', 'type' => 'video', 'status' => 'published', 'price' => 1000]);
        $group = Group::create(['teacher_subject_id' => $course->id, 'name' => 'مراجعة النحو', 'status' => 'active', 'capacity' => 10, 'session_price' => 3000]);

        return compact('user', 'teacher', 'subject', 'course', 'unit', 'lesson', 'content', 'group');
    }

    private function student(string $name = 'سلمى'): Student
    {
        return Student::create(['user_id' => User::factory()->create(['name' => $name, 'role' => 'student', 'status' => 'active'])->id, 'status' => 'active']);
    }

    private function url(string $path, array $query): string
    {
        return '/api/v1/'.$path.'?'.http_build_query($query);
    }

    public function test_single_arabic_character_search_is_paginated_and_excludes_suspended_teachers(): void
    {
        $f = $this->fixture();
        $other = $this->fixture('هالة');
        $other['teacher']->update(['status' => 'suspended']);
        $this->fixture('سامر');
        $this->getJson($this->url('teachers', ['q' => 'ه', 'per_page' => 1]))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $f['teacher']->id);
    }

    public function test_literal_wildcards_case_and_legacy_alias(): void
    {
        $f = $this->fixture('Tutor 100%_!');
        $this->fixture('Tutor 100xxx');
        foreach (['q', 'search'] as $key) {
            $this->getJson($this->url('teachers', [$key => '%_!']))->assertOk()->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.id', $f['teacher']->id);
        }
        $this->getJson($this->url('teachers', ['q' => 'tUtOr']))->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->url('teachers', ['q' => '', 'search' => 'no match']))->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->url('teachers', ['q' => "' OR 1=1 --"]))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_validation_and_empty_search(): void
    {
        $this->fixture();
        foreach ([['q' => ['bad']], ['q' => str_repeat('أ', 101)], ['per_page' => 101], ['page' => 0], ['governorate_id' => -1]] as $query) {
            $this->getJson($this->url('teachers', $query))->assertUnprocessable();
        }
        $this->getJson($this->url('teachers', ['q' => '   ']))->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_teacher_group_search_preserves_ownership_and_price_filters(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        // The match is outside the first unfiltered page (lists order by id desc).
        for ($i = 0; $i < 3; $i++) {
            Group::create(['teacher_subject_id' => $f['course']->id, 'name' => 'Other '.$i, 'capacity' => 10, 'status' => 'active', 'session_price' => 3000]);
        }
        Sanctum::actingAs($f['user']);
        $this->getJson($this->url('teacher/groups', ['q' => 'نحو', 'min_price' => 2500, 'max_price' => 3500, 'per_page' => 1]))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $f['group']->id);
        $this->getJson($this->url('teacher/groups', ['q' => 'نحو', 'teacher_subject_id' => $other['course']->id]))
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->url('teacher/groups', ['min_price' => 4000]))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->url('teacher/groups', ['min_price' => 5000, 'max_price' => 1000]))->assertUnprocessable();
    }

    public function test_group_members_and_join_requests_search_student_names_only_inside_owned_group(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $s = $this->student();
        $second = $this->student('مروان');
        foreach ([$s, $second] as $student) {
            GroupStudent::create(['group_id' => $f['group']->id, 'student_id' => $student->id, 'status' => 'active', 'joined_at' => now()]);
            GroupJoinRequest::create(['group_id' => $f['group']->id, 'student_id' => $student->id, 'status' => 'pending']);
        }
        Sanctum::actingAs($f['user']);
        foreach (['students', 'join-requests'] as $list) {
            $this->getJson($this->url('teacher/groups/'.$f['group']->id.'/'.$list, ['q' => 'سلم']))->assertOk()
                ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.student_id', $s->id);
            $this->getJson($this->url('teacher/groups/'.$other['group']->id.'/'.$list, ['q' => 'سلم']))->assertNotFound();
        }
    }

    public function test_physical_groups_search_before_pagination(): void
    {
        $f = $this->fixture();
        $s = $this->student();
        Sanctum::actingAs($s->user);
        $this->getJson($this->url('student/teachers/'.$f['teacher']->id.'/physical/groups', ['q' => 'هبة']))
            ->assertOk()->assertJsonPath('meta.total', 1);
        $f['group']->update(['status' => 'closed']);
        $this->getJson($this->url('student/teachers/'.$f['teacher']->id.'/physical/groups', ['q' => 'نحو']))
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_session_searches_morph_targets_and_filters_dates_in_requested_timezone(): void
    {
        $f = $this->fixture();
        $s = $this->student();
        $individual = Individual::create(['teacher_subject_id' => $f['course']->id, 'student_id' => $s->id, 'status' => 'active']);
        foreach ([['group', $f['group']->id], ['individual', $individual->id]] as [$type, $id]) {
            Session::create(['teacher_id' => $f['teacher']->id, 'type' => $type, 'type_id' => $id, 'status' => 'scheduled', 'starts_at' => '2026-11-19T00:30:00+03:00', 'ends_at' => '2026-11-19T01:30:00+03:00']);
        }
        Sanctum::actingAs($f['user']);
        $this->withHeader('X-Liqaa-Teaching-Contract', '2');
        $this->getJson($this->url('teacher/sessions', ['q' => 'سلمى', 'date_from' => '2026-11-19', 'date_to' => '2026-11-19']))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.type', 'individual');
        $this->getJson($this->url('teacher/sessions', ['q' => 'العربية']))->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->url('teacher/sessions', ['date_to' => '2026-11-18']))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->url('teacher/sessions', ['date_from' => '2026-11-19', 'date_to' => '2026-11-18']))->assertUnprocessable();
    }

    public function test_curriculum_filters_published_descendants_without_exposing_hidden_content(): void
    {
        $f = $this->fixture();
        $s = $this->student();
        Content::create(['lesson_id' => $f['lesson']->id, 'type' => 'pdf', 'title' => 'سري للغاية', 'status' => 'draft']);
        Sanctum::actingAs($s->user);
        $path = 'student/teachers/'.$f['teacher']->id.'/online-content';
        $this->getJson($this->url($path, ['q' => 'الأفعال']))->assertOk()->assertJsonCount(1, 'data.subjects');
        $this->getJson($this->url($path, ['q' => 'سري']))->assertOk()->assertJsonCount(0, 'data.subjects');
        $this->getJson($this->url('student/lessons/'.$f['lesson']->id.'/content', ['q' => 'شرح', 'content_type' => 'pdf']))
            ->assertOk()->assertJsonCount(0, 'data.contents');
        $this->getJson($this->url('lessons/'.$f['lesson']->id.'/contents', ['content_type' => 'video']))
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_enrollment_search_handles_all_supported_polymorphic_targets_and_is_private(): void
    {
        $f = $this->fixture();
        $s = $this->student();
        $other = $this->student('آخر');
        foreach ([$s, $other] as $student) {
            foreach ([$f['course'], $f['unit'], $f['lesson'], $f['content']] as $target) {
                Enrollment::create(['student_id' => $student->id, 'teacher_id' => $f['teacher']->id, 'enrollable_type' => $target->getMorphClass(), 'enrollable_id' => $target->id, 'status' => 'active']);
            }
        }
        Sanctum::actingAs($s->user);
        $this->getJson($this->url('student/enrollments', ['q' => 'الماضي']))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->url('student/enrollments', ['q' => 'هبة']))->assertOk()->assertJsonPath('meta.total', 4);
    }

    public function test_notification_feed_search_does_not_leak_other_users_or_change_global_unread_count(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        foreach ([[$f['user'], 'موعد'], [$f['user'], 'عام'], [$other['user'], 'موعد']] as [$user, $title]) {
            Notification::create(['user_id' => $user->id, 'type' => 'test', 'title' => $title, 'body' => 'رسالة']);
        }
        Sanctum::actingAs($f['user']);
        $this->getJson($this->url('teacher/notification-feed', ['q' => 'موعد']))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.unread_count', 2);
        $this->getJson($this->url('teacher/notifications', ['q' => 'موعد']))->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_search_does_not_reuse_results_across_teachers_or_after_data_changes(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        Sanctum::actingAs($f['user']);
        $url = $this->url('teacher/groups', ['q' => 'نحو']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $f['group']->id);
        Sanctum::actingAs($other['user']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $other['group']->id);
        $other['group']->update(['name' => 'إملاء']);
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_all_search_profiles_generate_valid_database_queries(): void
    {
        $models = [
            'teachers' => Teacher::class, 'groups' => Group::class, 'own_groups' => Group::class,
            'individuals' => Individual::class, 'members' => GroupStudent::class,
            'join_requests' => GroupJoinRequest::class, 'courses' => TeacherSubject::class, 'curriculum' => TeacherSubject::class,
            'subjects' => Subject::class, 'units' => Unit::class, 'lessons' => Lesson::class, 'contents' => Content::class,
            'assets' => ContentAsset::class, 'quizzes' => Quiz::class, 'sessions' => Session::class,
            'enrollments' => Enrollment::class, 'notifications' => Notification::class,
            'campaigns' => NotificationCampaign::class, 'plans' => SubscriptionPlan::class,
            'subscriptions' => TeacherSubscription::class, 'payments' => Payment::class,
            'refunds' => RefundRequest::class, 'locations' => Region::class,
            'availability' => TeacherAvailability::class,
        ];
        foreach ($models as $profile => $model) {
            $query = app(ListSearch::class)->apply($model::query(), Request::create('/', 'GET', ['q' => 'test']), $profile);
            $this->assertSame(0, $query->count(), $profile);
        }
    }
}
