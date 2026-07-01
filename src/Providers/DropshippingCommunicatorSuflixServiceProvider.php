<?php

namespace DropshippingCommunicatorSuflix\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use DropshippingCommunicatorSuflix\EventProcedures\SendOrderMailProcedure;

class DropshippingCommunicatorSuflixServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->getApplication()->register(DropshippingCommunicatorSuflixRouteServiceProvider::class);
        $this->getApplication()->bind(SendOrderMailProcedure::class);
    }

    public function boot(EventProceduresService $eventProceduresService)
    {
        $eventProceduresService->registerProcedure(
            'sendDropshippingCommunicatorSuflixMail',
            ProcedureEntry::EVENT_TYPE_ORDER,
            [
                'de' => 'DropshippingCommunicatorSuflix: E-Mail an Lieferanten senden',
                'en' => 'DropshippingCommunicatorSuflix: Send email to supplier'
            ],
            SendOrderMailProcedure::class . '@handle'
        );
    }
}
