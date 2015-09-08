<?php

namespace Fuzz\MagicBox\Routing;

use Fuzz\Agency\Contracts\Agent;
use Fuzz\MagicBox\Contracts\Repository;
use Fuzz\MagicBox\Utility\ModelResolver;
use Fuzz\User\Models\Group;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Class MagicMiddleware
 *
 * @package Fuzz\MagicBox\Routing
 */
class MagicMiddleware
{
	/**
	 * Handle an incoming request.
	 *
	 * @param  \Illuminate\Http\Request $request
	 * @param  \Closure                 $next
	 *
	 * @return mixed
	 */
	public function handle(Request $request, \Closure $next)
	{
		// Bind the repository contract to this concrete instance so it can be injected in resource routes
		app()->instance(Repository::class, $this->buildRepository($request));

		return $next($request);
	}

	/**
	 * Build a repository based on inbound request data.
	 *
	 * @param \Illuminate\Http\Request $request
	 *
	 * @return \Fuzz\MagicBox\Contracts\Repository;
	 */
	public function buildRepository(Request $request)
	{
		$input = [];

		/** @var \Illuminate\Routing\Route $route */
		$route = $request->route();

		// Resolve the model class if possible. And setup the repository.
		$model_class = (new ModelResolver())->resolveModelClass($route);

		// @TODO this shouldn't live here, needs to be moved somewhere else.
		// For all me routes we need to get the users id based on their access token.
		if ($request->segment(2) === 'me'
			&& app()->resolved(Agent::class)
			&& is_a($model_class, Agent::class, true))
		{
			$agent = app()->make(Agent::class);
			$input = [$agent->getKeyName() => $agent->getKey()];
		}

		// Look for /{model-class}/{id} RESTful requests
		// @TODO this is weird... Need to figure something out better.
		$parameters = $route->parametersWithoutNulls();
		if (!empty($parameters)) {
			$id = reset($parameters);
			$input = compact('id');
		}

		if ($request->method() !== 'GET') {
			$input += $request->all();
		}

		/** @var  \Fuzz\MagicBox\Contracts\Repository */
		$repository = config('magicbox.repository');

		// Instantiate an eloquent repository bound to our standardized route parameter
		$magic_box = (new $repository)->setModelClass($model_class)
			->setFilters((array)$request->get('filters'))
			->setSortOrder((array)$request->get('sort'))
			->setGroupBy((array)$request->get('group'))
			->setEagerLoads((array)$request->get('include'))
			->setAggregate((array)$request->get('aggregate'))
			->setInput($input);

		$this->showTrashed($magic_box, $request, $model_class);

		return $magic_box;
	}

	/**
	 * Show deleted query.
	 *
	 * @param \Fuzz\MagicBox\Contracts\Repository $repository
	 * @param \Illuminate\Http\Request            $request
	 * @param                                     $model_class
	 */
	private function showTrashed(Repository $repository, Request $request, $model_class)
	{
		if ($request->get('show_deleted')) {
			if (! isset($agent) && app()->resolved(Agent::class)) {
				$agent = app()->make(Agent::class);
			}

			if (in_array(SoftDeletes::class, class_uses_recursive($model_class)) && isset($agent) && $agent->is(Group::GROUP_ADMIN)) {
				$repository->setModifiers([
					function(Builder $query) {
						$query->withTrashed();
					}
				]);
			}
		}
	}
}
