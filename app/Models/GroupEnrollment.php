<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class GroupEnrollment extends Model {
    public $timestamps = false;
    protected $fillable = ['group_id','enrollment_id','status','joined_at','left_at'];
    protected function casts(): array { return ['joined_at'=>'datetime','left_at'=>'datetime']; }
    public function group(): BelongsTo { return $this->belongsTo(Group::class); }
    public function enrollment(): BelongsTo { return $this->belongsTo(Enrollment::class); }
}
