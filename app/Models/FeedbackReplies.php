<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeedbackReplies extends Model
{
    use HasFactory;

    protected $table = "feedback_replies";

    protected $fillable = ['feedback_id', 'user_id', 'message', 'status'];

    public function user(){
        return $this->belongsTo(User::class);
    }

    public function feedback(){
        return $this->belongsTo(Feedback::class);
    }
}
