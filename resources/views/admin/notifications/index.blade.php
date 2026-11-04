@extends('admin.layout')
@section('title','الإشعارات والحملات')
@section('content')
@php
$statuses=['draft'=>'مسودة','scheduled'=>'مجدولة','dispatched'=>'منشورة','cancelled'=>'ملغاة'];
$scopes=['all'=>'جميع الطلاب والمدرسين','students'=>'جميع الطلاب','teachers'=>'جميع المدرسين','student'=>'طالب محدد','teacher'=>'مدرس محدد'];
$editable=in_array($editing->status,['draft','scheduled'],true);
$tz=$dashboardSettings['timezone'];
@endphp
<div class="page-heading"><div><span class="eyebrow">التواصل مع مستخدمي المنصة</span><h1>الإشعارات والحملات</h1><p>إنشاء إشعار عام أو موجّه، وحفظه كمسودة أو جدولة نشره.</p></div><a class="button" href="{{ route('admin.notifications.index') }}#campaign-form">إنشاء حملة جديدة</a></div>
<div class="notice info">«منشورة» تعني ظهور الحملة في صندوق إشعارات المستلمين، ولا تعني تأكيد وصولها إلى الهاتف أو قراءتها. إرسال الأجهزة يحتاج عامل قائمة الانتظار، والنشر المجدول يحتاج مشغّل الجدولة. الأوقات بتوقيت {{ $tz }}.</div>
<div class="detail-layout"><section class="panel">
<form method="get" class="form-actions"><label>الحالة<select name="status"><option value="">كل الحالات</option>@foreach($statuses as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select></label><button class="button secondary">تصفية</button><a href="{{ route('admin.notifications.index') }}">مسح التصفية</a></form>
<div class="table-wrap"><table><thead><tr><th>الحملة</th><th>المستلمون</th><th>الحالة</th><th>موعد النشر</th><th>الإجراء</th></tr></thead><tbody>
@forelse($items as $item)<tr><td><strong>{{ $item->title }}</strong><small>{{ \Illuminate\Support\Str::limit($item->body,90) }}</small></td><td>{{ $scopes[$item->target_scope] ?? $item->target_scope }} @if($item->target_user_id)<small>{{ $recipients->get($item->target_user_id)?->name ?? 'حساب غير متاح' }}</small>@endif</td><td><span class="badge neutral">{{ $statuses[$item->status] ?? $item->status }}</span></td><td>{{ ($item->dispatched_at ?? $item->scheduled_at)?->timezone($tz)->format('Y/m/d H:i') ?? '—' }}</td><td><a class="button small outline" href="{{ route('admin.notifications.index',['edit'=>$item->id]) }}#campaign-form">{{ in_array($item->status,['draft','scheduled'])?'عرض وتعديل':'عرض' }}</a></td></tr>
@empty<tr><td colspan="5"><div class="empty-state"><x-admin-icon name="bell"/><strong>لا توجد حملات إشعارات</strong></div></td></tr>@endforelse
</tbody></table></div>{{ $items->links('admin.partials.pagination') }}</section>
<aside><section class="panel" id="campaign-form"><h2>{{ $editing->exists?'الحملة #'.$editing->id:'إنشاء حملة' }}</h2>
@if($editable)
<form method="post" action="{{ $editing->exists?route('admin.notifications.update',$editing):route('admin.notifications.store') }}">@csrf @if($editing->exists)@method('put')@endif
<div class="field-grid"><label class="full">عنوان الإشعار *<input name="title" required maxlength="180" value="{{ old('title',$editing->title) }}"></label>
<label class="full">نص الإشعار *<textarea name="body" required maxlength="2000" rows="5">{{ old('body',$editing->body) }}</textarea></label>
<label class="full">المستلمون *<select name="target_scope" id="campaign-scope">@foreach($scopes as $key=>$label)<option value="{{ $key }}" @selected(old('target_scope',$editing->target_scope)===$key)>{{ $label }}</option>@endforeach</select></label>
<div class="full" id="campaign-recipient"><label>بحث باسم المستلم<input type="search" id="recipient-search" maxlength="100" placeholder="اكتب اسم الطالب أو المدرّس" autocomplete="off"></label><label>اسم المستلم *<select name="target_user_id" id="recipient-select"><option value="">اختر المستلم</option>@if($selected=$recipients->get(old('target_user_id',$editing->target_user_id)))<option value="{{ $selected->id }}" selected>{{ $selected->name }}{{ $selected->phone?' — '.$selected->phone:'' }}</option>@endif</select></label><small id="recipient-status" role="status">ابحث بالاسم ثم اختر الحساب. رقم الهاتف يساعدك على تمييز الأسماء المتشابهة.</small></div>
<label class="full">موعد النشر (اختياري)<input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at',$editing->scheduled_at?->timezone($tz)->format('Y-m-d\TH:i')) }}"><small>اتركه فارغاً للحفظ كمسودة، أو حدد وقتاً مستقبلياً للجدولة.</small></label></div>
<div class="form-actions"><button class="button">حفظ الحملة</button></div></form>
@else
<h3>{{ $editing->title }}</h3><p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $editing->body }}</p><p>المستلمون: {{ $scopes[$editing->target_scope] ?? $editing->target_scope }} {{ $recipients->get($editing->target_user_id)?->name }}</p><p>الحالة: {{ $statuses[$editing->status] }}</p><p class="muted">الحملات المنشورة أو الملغاة محفوظة للعرض ولا يمكن تعديلها أو سحب إشعار منشور.</p>
@endif
@if($editing->exists && $editable)
<div class="form-actions"><form method="post" action="{{ route('admin.notifications.dispatch',$editing) }}" data-confirm="نشر النسخة المحفوظة الآن لجميع المستلمين المحددين؟ احفظ أي تعديلات أولاً. لا يمكن سحبها بعد النشر.">@csrf<button class="button">نشر الآن</button></form><form method="post" action="{{ route('admin.notifications.cancel',$editing) }}" data-confirm="إلغاء هذه الحملة وإيقاف نشرها المجدول؟">@csrf<button class="button danger">إلغاء الحملة</button></form></div><small>النشر والإلغاء يطبّقان على النسخة المحفوظة. احفظ تعديلاتك أولاً.</small>
@endif
</section></aside></div>
<script>
document.addEventListener('DOMContentLoaded',()=>{
const scope=document.getElementById('campaign-scope'),row=document.getElementById('campaign-recipient');if(!scope)return;
const search=document.getElementById('recipient-search'),select=document.getElementById('recipient-select'),status=document.getElementById('recipient-status');
let timer,controller,version=0;
async function load(){const current=++version;controller?.abort();controller=new AbortController();const selected=select.selectedOptions[0]?.value?select.selectedOptions[0].cloneNode(true):null;status.textContent='جارٍ البحث…';
try{const url=new URL(@json(route('admin.notifications.recipients')),location.origin);url.searchParams.set('scope',scope.value);url.searchParams.set('q',search.value);const response=await fetch(url,{headers:{Accept:'application/json'},signal:controller.signal});if(!response.ok)throw Error();const result=await response.json();if(current!==version)return;select.replaceChildren(new Option('اختر المستلم',''));if(selected)select.add(selected);for(const user of result.data){if(selected?.value===String(user.id))continue;select.add(new Option(user.name+(user.phone?' — '+user.phone:''),user.id));}status.textContent=result.data.length?'اختر المستلم. تظهر أول 30 نتيجة؛ اكتب الاسم لتضييق البحث.':'لا توجد أسماء مطابقة.';
}catch(error){if(error.name!=='AbortError'&&current===version)status.textContent='تعذر تحميل الأسماء. أعد البحث.';}}
function toggle(reset){clearTimeout(timer);controller?.abort();++version;const single=['student','teacher'].includes(scope.value);row.hidden=!single;select.disabled=!single;select.required=single;search.disabled=!single;if(reset){select.replaceChildren(new Option('اختر المستلم',''));search.value='';}if(single)load();}
scope.addEventListener('change',()=>toggle(true));search.addEventListener('input',()=>{clearTimeout(timer);controller?.abort();++version;timer=setTimeout(load,300);});toggle(false);
});
</script>
@endsection
