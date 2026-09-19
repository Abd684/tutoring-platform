<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Subject extends Model {
    public $timestamps = false;
    protected $fillable = ['name','description','status'];
    public function teacherSubjects(): HasMany { return $this->hasMany(TeacherSubject::class); }
}
