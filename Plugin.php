<?php

namespace AldirBlancValidador;

use MapasCulturais\App;
use MapasCulturais\Entities\Registration;

class Plugin extends \AldirBlanc\PluginValidador
{
    function __construct(array $config = [])
    {
        $config += [
            // slug utilizado como id do controller
            'slug' => 'generico',

            // nome apresentado na interface
            'name' => 'Validador Genérico',

            // se true, só exporta as inscrições pendentes que já tenham alguma avaliação
            'exportador_requer_homologacao' => true,

            // se true, só exporta as inscrićòes 
            'exportador_requer_validacao' => ['dataprev'],
            
            'inciso1' => [
                'número' => 'number',
            ],
            'inciso2_cpf' => [
                'número' => 'number'
            ],
            'inciso2_cnpj' => [
                'número' => 'number'
            ],
        ];
        $this->_config = $config;
        parent::__construct($config);
    }

    function _init()
    {
        $app = App::i();

        $plugin_aldirblanc = $app->plugins['AldirBlanc'];
        $plugin_validador = $this;

        //botao de export csv
        $app->hook('template(opportunity.single.header-inscritos):end', function () use($plugin_aldirblanc, $plugin_validador, $app){
            $inciso1Ids = [$plugin_aldirblanc->config['inciso1_opportunity_id']];
            $inciso2Ids = array_values($plugin_aldirblanc->config['inciso2_opportunity_ids']);
            $opportunities_ids = array_merge($inciso1Ids, $inciso2Ids);
            $requestedOpportunity = $this->controller->requestedEntity; //Tive que chamar o controller para poder requisitar a entity
            $opportunity = $requestedOpportunity->id;
            if(($requestedOpportunity->canUser('@control')) && in_array($requestedOpportunity->id,$opportunities_ids) ) {
                $app->view->enqueueScript('app', 'aldirblanc', 'validador/app.js');
                if (in_array($requestedOpportunity->id, $inciso1Ids)){
                    $inciso = 1;
                }
                else if (in_array($requestedOpportunity->id, $inciso2Ids)){
                    $inciso = 2;
                }
                $this->part('validador/csv-button', ['inciso' => $inciso, 'opportunity' => $opportunity, 'plugin_aldirblanc' => $plugin_aldirblanc, 'plugin_validador' => $plugin_validador]);
            }
        });

        // uploads de CSVs 
        $app->hook('template(opportunity.<<single|edit>>.sidebar-right):end', function () use($plugin_aldirblanc, $plugin_validador) {
            $opportunity = $this->controller->requestedEntity; 
            if($opportunity->canUser('@control')){
                $this->part('validador/validador-uploads', ['entity' => $opportunity, 'plugin_aldirblanc' => $plugin_aldirblanc, 'plugin_validador' => $plugin_validador]);
            }
        });

        parent::_init();
    }

    function register()
    {
        $app = App::i();
        $slug = $this->getSlug();

        $this->registerOpportunityMetadata($slug . '_processed_files', [
            'label' => 'Arquivos do Dataprev Processados',
            'type' => 'json',
            'private' => true,
            'default_value' => '{}'
        ]);

        $this->registerRegistrationMetadata($slug . '_filename', [
            'label' => 'Nome do arquivo de retorno do dataprev',
            'type' => 'string',
            'private' => true,
        ]);

        $this->registerRegistrationMetadata($slug . '_raw', [
            'label' => 'Dataprev raw data (csv row)',
            'type' => 'json',
            'private' => true,
            'default_value' => '{}'
        ]);

        $this->registerRegistrationMetadata($slug . '_processed', [
            'label' => 'Dataprev processed data',
            'type' => 'json',
            'private' => true,
            'default_value' => '{}'
        ]);

        $file_group_definition = new \MapasCulturais\Definitions\FileGroup($slug, ['^text/csv$'], 'O arquivo enviado não é um csv.',false,null,true);
        $app->registerFileGroup('opportunity', $file_group_definition);

        parent::register();

        $app->controller($slug)->plugin = $this;
    }

    function getName(): string
    {
        return $this->_config['name'];
    }

    function getSlug(): string
    {
        return $this->_config['slug'];
    }

    function getControllerClassname(): string
    {
        return Controller::class;
    }

    function isRegistrationEligible(Registration $registration): bool
    {
        return true;
    }
}
