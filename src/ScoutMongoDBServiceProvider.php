<?php

namespace QRStuff\Scout\MongoDB;

use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;

class ScoutMongoDBServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->resolving(EngineManager::class, function (EngineManager $em) {
            $em->extend('mongodb', function ($app) {
                $name = $app->get('config')->get('scout.mongodb.connection', 'mongodb');
                $connection = $app->get('db')->connection($name);
                $isSoftDeleteEnabled = (bool) $app->get('config')->get('scout.soft_delete', false);

                return new MongoDBScoutEngine($connection->getMongoDB(), $isSoftDeleteEnabled);
            });

            return $em;
        });
    }
}
