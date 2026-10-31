<div class="teacher-workspace">
<div class="profile-header"><div class="profile-identity"><span class="avatar large">{{ mb_substr($teacherUser->name,0,1) }}</span><div><a class="eyebrow" href="{{ route('admin.teachers.index') }}">المدرسون / ملف المدرس #{{ $teacherUser->teacher->id }}</a><h1>{{ $teacherUser->name }}</h1><p>{{ $teacherUser->teacher->specialization?:'لم يحدد التخصص' }} • {{ $teacherUser->email }}</p></div></div><a class="button outline" href="{{ route('admin.teachers.edit',$teacherUser) }}"><x-admin-icon name="edit"/>تعديل الملف</a></div>
<nav class="tabs workspace-tabs" aria-label="تبويبات المدرس">
@foreach(['profile'=>'الملف الشخصي','courses'=>'المواد الدراسية','groups'=>'المجموعات','sessions'=>'الجلسات','payments'=>'العمليات المالية','subscriptions'=>'الاشتراكات'] as $key=>$label)
@php($url=in_array($key,['groups','sessions'])?route('admin.'.$key.'.index',['teacherUser'=>$teacherUser]):route('admin.teachers.show',['user'=>$teacherUser,'tab'=>$key]))
<a href="{{ $url }}" @class(['active'=>$activeTab===$key]) @if($activeTab===$key) aria-current="page" @endif>{{ $label }}</a>
@endforeach
</nav></div>
