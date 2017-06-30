<?php namespace Laravelista\Comments\Comments\Traits;

use Laravelista\Comments\Comments\Comment;

trait Comments
{
  public function comments()
  {
    return $this->morphMany($this->comment_class?? Comment::class, 'content');
  }
}
