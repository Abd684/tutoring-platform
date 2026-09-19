<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class TeacherSubscription extends Model {
    public $timestamps = false;
    protected $fillable = ['teacher_id','plan_id','starts_at','ends_at','auto_renew','status'];
    protected function casts(): array { return ['starts_at'=>'datetime','ends_at'=>'datetime','auto_renew'=>'boolean']; }
    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
    public function plan(): BelongsTo { return $this->belongsTo(SubscriptionPlan::class, 'plan_id'); }
}
