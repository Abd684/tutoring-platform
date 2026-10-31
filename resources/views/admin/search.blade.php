@extends('admin.layout')
@section('title','نتائج البحث')
@section('content')
<div class="page-heading"><div><span class="eyebrow">بحث شامل</span><h1>نتائج البحث</h1><p>{{ $term!==''?'نتائج مطابقة لـ «'.$term.'»':'اكتب عبارة للبحث في الحسابات والمجموعات والمحتوى والمدفوعات.' }}</p></div><span class="badge neutral">{{ count($results) }} نتيجة</span></div><section class="panel padded"><div class="search-results">@forelse($results as $result)<a class="search-result" href="{{ $result['url'] }}"><div><span class="eyebrow">{{ $result['kind'] }}</span><strong>{{ $result['title'] }}</strong><small>{{ $result['detail'] }}</small></div><x-admin-icon name="arrow"/></a>@empty<div class="empty-state"><x-admin-icon name="search"/><strong>لا توجد نتائج مطابقة</strong><span>جرّب اسماً أو رقماً مرجعياً آخر.</span></div>@endforelse</div></section>
@endsection
