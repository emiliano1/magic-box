<?php

namespace Fuzz\MagicBox\Tests\Models;

use Fuzz\Data\Eloquent\Model;

class Tag extends Model
{
	public $timestamps = false;

	public function posts()
	{
		return $this->belongsToMany('Fuzz\MagicBox\Tests\Models\Post')->withPivot('extra');
	}
}
