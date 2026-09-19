<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class SubscriptionPlan extends Model {
    public $timestamps = false;
    protected $fillable = ['name','price','billing_cycle','commission_rate','max_students','max_storage','max_ai_usage','max_group_size','status'];
    protected function casts(): array { return ['price'=>'decimal:2','commission_rate'=>'decimal:2']; }
    public function teacherSubscriptions(): HasMany { return $this->hasMany(TeacherSubscription::class, 'plan_id'); }
}
