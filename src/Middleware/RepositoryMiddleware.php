<?php

namespace Fuzz\MagicBox\Middleware;

use Fuzz\MagicBox\Utility\ExplicitModelResolver;
use Illuminate\Http\Request;
use Fuzz\MagicBox\Utility\Modeler;
use Illuminate\Support\Facades\Auth;
use Fuzz\MagicBox\EloquentRepository;
use Fuzz\MagicBox\Contracts\Repository;
use Fuzz\HttpException\AccessDeniedHttpException;

class RepositoryMiddleware
{
	/**
	 * Handle an incoming request.
	 *
	 * @param  \Illuminate\Http\Request $request
	 * @param  \Closure                 $next
	 * @return mixed
	 */
	public function handle(Request $request, \Closure $next)
	{
		$this->buildRepository($request);

		return $next($request);
	}

	/**
	 * Build a repository based on inbound request data.
	 *
	 * @param \Illuminate\Http\Request $request
	 * @return \Fuzz\MagicBox\EloquentRepository
	 */
	public function buildRepository(Request $request): EloquentRepository
	{
		$input = [];

		/** @var \Illuminate\Routing\Route $route */
		$route = $request->route();

		// Resolve the model class if possible. And setup the repository.
		/** @var \Illuminate\Database\Eloquent\Model $model_class */
		$model_class = (new ExplicitModelResolver)->resolveModelClass($route);

		// If the method is not GET lets get the input from everywhere.
		// @TODO hmm, need to verify what happens on DELETE and PATCH.
		if ($request->method() !== 'GET') {
			$input += $request->all();
		}

		// Resolve an eloquent repository bound to our standardized route parameter
		$repository = resolve(Repository::class);

		$repository->setModelClass($model_class)
			->setFilters((array) $request->get('filters'))
			->setSortOrder((array) $request->get('sort'))
			->setGroupBy((array) $request->get('group'))
			->setEagerLoads((array) $request->get('include'))
			->setAggregate((array) $request->get('aggregate'))
			->setDepthRestriction(config('magic-box.eager_load_depth'))
			->setInput($input);
		return $repository;
	}
}
