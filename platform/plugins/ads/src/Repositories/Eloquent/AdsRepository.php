<?php

namespace Botble\Ads\Repositories\Eloquent;

use Botble\Ads\Models\Ads;
use Botble\Ads\Repositories\Interfaces\AdsInterface;
use Botble\Support\Repositories\Eloquent\RepositoriesAbstract;
use Illuminate\Database\Eloquent\Collection;

class AdsRepository extends RepositoriesAbstract implements AdsInterface
{
    public function getAll(): Collection
    {
        $data = Ads::query()
            ->wherePublished()
            ->notExpired()
            ->with(['metadata']);

        return $this->applyBeforeExecuteQuery($data)->get();
    }
}
