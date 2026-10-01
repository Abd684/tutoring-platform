<?php

namespace Database\Seeders;

use App\Models\Content;
use App\Models\Enrollment;
use App\Models\Governortate;
use App\Models\Lesson;
use App\Models\Region;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubscriptionPlan;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\TeacherSubscription;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MobileMediaTestSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('Mobile media test accounts must not be seeded in production.');
        }

        $data = DB::transaction(function (): array {
            $governorate = Governortate::firstOrCreate(['name' => 'دمشق - تجربة الجوال']);
            $region = Region::firstOrCreate([
                'name' => 'منطقة تجربة الجوال',
                'governortate_id' => $governorate->id,
            ]);
            $school = School::firstOrCreate([
                'name' => 'مدرسة تجربة الجوال',
                'region_id' => $region->id,
            ]);

            $teacherUser = $this->testUser('teacher.mobile@example.com', '0997000001', 'أستاذ تجربة الجوال', 'teacher', $region->id);
            $teacher = Teacher::firstOrCreate(['user_id' => $teacherUser->id], [
                'specialization' => 'رياضيات',
                'bio' => 'حساب تجريبي لرفع الفيديو وملفات الدروس.',
                'status' => 'active',
            ]);

            $students = [];
            foreach ([
                ['student.mobile@example.com', '0997000002', 'طالب مشترك تجريبي'],
                ['student.unsubscribed@example.com', '0997000003', 'طالب غير مشترك تجريبي'],
            ] as [$email, $phone, $name]) {
                $user = $this->testUser($email, $phone, $name, 'student', $region->id);
                $students[] = Student::firstOrCreate(['user_id' => $user->id], [
                    'grade' => '12',
                    'school_id' => $school->id,
                    'status' => 'active',
                ]);
            }

            $plan = SubscriptionPlan::firstOrCreate(['name' => 'Mobile Media Test Plan'], [
                'price' => 0,
                'billing_cycle' => 'monthly',
                'commission_rate' => 0,
                'max_students' => 100,
                'max_storage' => 10240,
                'max_ai_usage' => 100,
                'max_group_size' => 10,
                'status' => 'active',
            ]);
            TeacherSubscription::updateOrCreate([
                'teacher_id' => $teacher->id,
                'plan_id' => $plan->id,
            ], [
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonths(3),
                'auto_renew' => false,
                'status' => 'active',
            ]);

            $subject = Subject::firstOrCreate(['name' => 'رياضيات - تجربة الجوال'], [
                'description' => 'بيانات تجريبية لاختبار المنهاج ورفع الملفات وتشغيلها.',
                'status' => 'active',
            ]);
            $teacherSubject = TeacherSubject::firstOrCreate([
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
            ], ['price' => 100, 'group_enabled' => false, 'status' => 'active']);

            $contents = [];
            foreach (['الجبر', 'الهندسة'] as $unitIndex => $unitTitle) {
                $unit = Unit::firstOrCreate([
                    'teacher_subject_id' => $teacherSubject->id,
                    'title' => $unitTitle.' - تجربة الجوال',
                ], ['price' => 50, 'order_no' => $unitIndex + 1, 'status' => 'published']);

                foreach ([1, 2] as $lessonNo) {
                    $lesson = Lesson::firstOrCreate([
                        'unit_id' => $unit->id,
                        'title' => "الدرس {$lessonNo} - {$unitTitle}",
                    ], ['order_no' => $lessonNo, 'status' => 'published']);

                    foreach (['video' => 'فيديو شرح', 'pdf' => 'ملف الدرس'] as $type => $title) {
                        $content = Content::firstOrCreate([
                            'lesson_id' => $lesson->id,
                            'type' => $type,
                            'title' => "{$title} - {$unitTitle} {$lessonNo}",
                        ], [
                            'description' => 'جاهز لرفع ملف تجريبي من حساب الأستاذ.',
                            'price' => 10,
                            'order_no' => $type === 'video' ? 1 : 2,
                            'status' => 'published',
                            'published_at' => now(),
                        ]);
                        $contents[] = [$content->id, $type, $content->title, $lesson->id, $unit->id];
                    }
                }
            }

            Enrollment::updateOrCreate([
                'student_id' => $students[0]->id,
                'teacher_id' => $teacher->id,
                'enrollable_type' => TeacherSubject::class,
                'enrollable_id' => $teacherSubject->id,
            ], [
                'price' => 100,
                'status' => 'active',
                'starts_at' => now()->subDay(),
                'expires_at' => now()->addMonths(3),
            ]);

            return ['teacher' => $teacher, 'teacher_subject' => $teacherSubject, 'students' => $students, 'contents' => $contents];
        });

        $this->command?->info('Mobile media test data is ready. Existing files and device sessions were preserved.');
        $this->command?->line('Teacher: teacher.mobile@example.com / MobileTest123!');
        $this->command?->line('Enrolled student: student.mobile@example.com / MobileTest123!');
        $this->command?->line('Unsubscribed student: student.unsubscribed@example.com / MobileTest123!');
        $this->command?->line('Teacher ID: '.$data['teacher']->id.' | Teacher subject ID: '.$data['teacher_subject']->id);
        $this->command?->line('Student IDs: '.$data['students'][0]->id.' (enrolled), '.$data['students'][1]->id.' (unsubscribed)');
        $this->command?->table(['Content ID', 'Type', 'Title', 'Lesson ID', 'Unit ID'], $data['contents']);
        $this->command?->line('Upload a real file using POST /api/v1/teacher/contents/{content_id}/asset (form-data: file).');
    }

    private function testUser(string $email, string $phone, string $name, string $role, int $regionId): User
    {
        return User::firstOrCreate(['email' => $email], [
            'name' => $name,
            'phone' => $phone,
            'password_hash' => Hash::make('MobileTest123!'),
            'role' => $role,
            'status' => 'active',
            'region_id' => $regionId,
        ]);
    }
}
