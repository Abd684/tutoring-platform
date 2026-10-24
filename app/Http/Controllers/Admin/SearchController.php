<?php

namespace App\Http\Controllers\Admin;

use App\Models\Content;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;

class SearchController extends AdminController
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim($data['q'] ?? '');
        $results = [];
        if ($term !== '') {
            foreach (User::whereIn('role', ['student', 'teacher'])->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"))->limit(10)->get() as $user) {
                $results[] = ['title' => $user->name, 'kind' => $user->role === 'student' ? 'طالب' : 'مدرس', 'detail' => $user->email, 'url' => route('admin.'.($user->role === 'student' ? 'students' : 'teachers').'.show', $user)];
            }
            foreach (Content::where('title', 'like', "%{$term}%")->limit(8)->get() as $row) {
                $results[] = ['title' => $row->title, 'kind' => 'محتوى تعليمي', 'detail' => '#'.$row->id, 'url' => route('admin.content.edit', $row)];
            }
            foreach (Group::where('name', 'like', "%{$term}%")->limit(8)->get() as $row) {
                $results[] = ['title' => $row->name, 'kind' => 'مجموعة', 'detail' => '#'.$row->id, 'url' => route('admin.groups.show', $row)];
            }
            foreach (Payment::where('provider_reference', 'like', "%{$term}%")->limit(8)->get() as $row) {
                $results[] = ['title' => $row->provider_reference, 'kind' => 'دفعة', 'detail' => $row->amount.' '.$row->currency, 'url' => route('admin.payments.show', $row)];
            }
        }

        return view('admin.search', compact('results','term'));
    }
}
