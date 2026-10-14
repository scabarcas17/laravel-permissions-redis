<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Reads the `user_model` config entry, which accepts a single class name or
 * a list of them (multi-model setups).
 */
trait ResolvesUserModels
{
    /**
     * @return array<int, class-string<Model>>
     */
    private function configuredUserModels(): array
    {
        $model = config('permissions-redis.user_model', 'App\\Models\\User');

        if (is_array($model)) {
            /** @var array<class-string<Model>> $model */
            return array_values($model);
        }

        /** @var class-string<Model> $model */
        return [$model];
    }

    /**
     * The first configured user model, used where only one class can be
     * targeted (for example Eloquent relations).
     *
     * @return class-string<Model>
     */
    private function primaryUserModel(): string
    {
        return $this->configuredUserModels()[0];
    }

    private function isConfiguredUserModel(object $user): bool
    {
        foreach ($this->configuredUserModels() as $model) {
            if ($user instanceof $model) {
                return true;
            }
        }

        return false;
    }
}
