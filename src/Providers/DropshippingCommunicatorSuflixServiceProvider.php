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
    }

    public function boot(EventProceduresService $eventProceduresService)
    {
        $eventProceduresService->registerProcedure(
            'DropshippingCommunicatorSuflix_sendOrderMail',
            ProcedureEntry::EVENT_TYPE_ORDER,
            [
                'de' => 'Suflix: TXT + Lieferschein per E-Mail senden',
                'en' => 'Suflix: Send TXT + delivery note by email'
            ],
            SendOrderMailProcedure::class . '@handle'
        );
    }
}
