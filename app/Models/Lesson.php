<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Lesson extends Model {
    public $timestamps = false;
    protected $fillable = ['unit_id','title','description','order_no','status'];
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class); }
    public function contents(): HasMany { return $this->hasMany(Content::class); }
}
