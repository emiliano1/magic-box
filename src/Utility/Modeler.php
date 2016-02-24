<?php

namespace Fuzz\MagicBox\Utility;

use Fuzz\MagicBox\Contracts\MagicBoxResource;
use Illuminate\Support\Str;
use Illuminate\Routing\Route;
use Illuminate\Console\AppNamespaceDetectorTrait;

class Modeler
{
	use AppNamespaceDetectorTrait;

	/**
	 * Resolve and return the model class for requests.
	 *
	 * @param \Illuminate\Routing\Route $route
	 * @return string
	 */
	final public function resolveModelClass(Route $route)
	{
		// The plural resource name is always the second URL segment, after the API version
		$route_name = $route->getName();

		if (! is_null($route_name) && strpos($route_name, '.') !== false) {
			list(, $alias) = array_reverse(explode('.', $route->getName()));

			$model_class = $this->namespaceModel(Str::studly(Str::singular($alias)));

			if (is_a($model_class, MagicBoxResource::class, true)) {
				return $model_class;
			}

			throw new \LogicException(sprintf('%s must be an instance of %s', $model_class, MagicBoxResource::class));
		}

		throw new \LogicException('Unable to resolve model from improperly named route');
	}

	/**
	 * Attach the app namespace to the model and return it.
	 *
	 * @param string $model_class
	 * @return string
	 */
	final public function namespaceModel($model_class)
	{
		return sprintf('%s%s', $this->getAppNamespace(), $model_class);
	}
}
