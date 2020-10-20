<?php

namespace AldirBlancValidador;

use DateTime;
use Doctrine\ORM\ORMException;
use Exception;
use InvalidArgumentException;
use League\Csv\Writer;
use League\Csv\Reader;
use League\Csv\Statement;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\i;

/**
 * Registration Controller
 *
 * By default this controller is registered with the id 'registration'.
 *
 *  @property-read \MapasCulturais\Entities\Registration $requestedEntity The Requested Entity
 */
// class AldirBlanc extends \MapasCulturais\Controllers\EntityController {
class Controller extends \MapasCulturais\Controller
{
    protected $config = [];

    protected $instanceConfig = [];

    protected $plugin;

    public function setPlugin(Plugin $plugin)
    {
        $this->plugin = $plugin;
        
        $app = App::i();

        $this->config = $app->plugins['AldirBlanc']->config;
        $this->config += $this->plugin->config;
    }

    protected function exportInit(Opportunity $opportunity) {
        $this->requireAuthentication();

        if (!$opportunity->canUser('@control')) {
            echo "Não autorizado";
            die();
        }

        $this->registerRegistrationMetadata($opportunity);

        //Seta o timeout
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '768M');

    }

    /**
     * Retorna as inscrições
     * @param Opportunity $opportunity 
     * @return Registration[]
     */
    protected function getRegistrations(Opportunity $opportunity){
        $app = App::i();

        // status das inscrições
        $status = intval($this->data['status'] ?? 1);

        $dql_params = [
            'opportunity_Id' => $opportunity->id,
            'status' => $status,
        ];

        $from = $this->data['from'] ?? '';
        $to = $this->data['to'] ?? '';

        if ($from && !DateTime::createFromFormat('Y-m-d', $from)) {
            throw new \Exception("O formato do parâmetro `from` é inválido.");
        }

        if ($to && !DateTime::createFromFormat('Y-m-d', $to)) {
            throw new \Exception("O formato do parâmetro `to` é inválido.");
        }

        if ($from) {
            //Data ínicial
            $dql_params['from'] (new DateTime($from))->format('Y-m-d 00:00');
            $dql_from = "e.sentTimestamp >= :from AND";
        }

        if ($to) {
            //Data Final
            $dql_params['to'] (new DateTime($to))->format('Y-m-d 00:00');
            $dql_to = "e.sentTimestamp >= :to AND";
        }

        $dql = "
            SELECT
                e
            FROM
                MapasCulturais\Entities\Registration e
            WHERE
                $dql_to
                $dql_from
                e.status = :status AND
                e.opportunity = :opportunity_Id";

        $query = $app->em->createQuery($dql);

        $query->setParameters($dql_params);

        $result = $query->getResult();

        /**
         * remove da lista as inscrições não homologadas, as já validadas por
         * este validador e as inscrições que não tenham sido validadas pelos 
         * validadores requeridos.
         */ 
        $registrations = [];

        $repo = $app->repo('RegistrationEvaluation');
        $validator_user = $this->plugin->getUser();

        foreach ($result as $registration) {
            $evaluations = $repo->findBy(['registration' => $registration, 'status' => 1]);
            
            $eligible = true;

            // verifica se este validador já validou esta inscrição
            foreach ($evaluations as $evaluation) {
                if($validator_user->equals($evaluation->user)) {
                    $eligible = false;
                }
            }
            
            /**  
             * se configurado, verifica se a inscrição está homologada
             * @todo: implementar para outros métodos de avaliação 
             */
            if ($this->config['exportador_requer_homologacao']) {    
                $homologado = false;

                // tem que ter uma avaliação com status `selecionado` (10)
                foreach ($evaluations as $evaluation) {
                    if ((!$evaluation->user->aldirblanc_validador) && $evaluation->result == '10') {
                        $homologado = true;
                    }
                }

                // mas não pode ter uma avaliação com status diferente de `selecionado` (2, 3)
                foreach ($evaluations as $evaluation) {
                    if ((!$evaluation->user->aldirblanc_validador) && $evaluation->result != '10') {
                        $homologado = false;
                    }
                }

                if(!$homologado) {
                    $eligible = false;
                }
            }

            /**  
             * se configurado, verifica se a inscrição está validada pelos validadores
             * @todo: implementar para outros métodos de avaliação 
             */
            foreach ($this->config['exportador_requer_validacao'] as $validador_slug) {
                if(!$eligible) {
                    continue;
                }
                $validated = false;
                foreach ($evaluations as $evaluation) {
                    if ($evaluation->user->aldirblanc_validador == $validador_slug && $evaluation->result == '10') {
                        $validated = true;
                    }
                }
                if (!$validated) {
                    $eligible = false;
                }
            }

            if($eligible) {
                $registrations[] = $registration;
            }
        }


        $app->applyHookBoundTo($this, 'validator(' . $this->plugin->getSlug() . ').registrations', [&$registrations, $opportunity]);

        return $registrations;        
    }

    protected function generateCSV(string $prefix, array $registrations, array $fields):string {
        /**
         * Array com header do documento CSV
         * @var array $headers
         */
        $headers = array_keys($fields);

        $csv_data = [];

        foreach ($registrations as $i => $registration) {
            $csv_data[$i] = [];

            foreach ($fields as $key => $field) {
                if (is_callable($field)) {
                    $value = $field($registration, $key);
                } else if (is_string($field)) {
                    $value = $registration->$field;
                } else if (is_int($field)) { 
                    $field = "field_{$field}";
                    $value = $registration->$field;
                } else {
                    $value = $field;
                }

                if (is_array($value)) {
                    $value = implode(',', $value);
                }

                $csv_data[$i] = $value;
            }
        }

        $validador = $this->plugin->getSlug();
        $hash = md5(json_encode($csv_data));

        $dir = PRIVATE_FILES_PATH . 'aldirblanc/inciso1/';

        $filename =  $dir . "{$validador}-{$prefix}-{$hash}.csv";

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $stream = fopen($filename, 'w');

        $csv = Writer::createFromStream($stream);

        $csv->insertOne($headers);

        foreach ($csv_data as $csv_line) {
            $csv->insertOne($csv_line);
        }

        return $filename;
    }

    /**
     * Exportador para o inciso 1
     *
     * Implementa o sistema de exportação para a lei AldirBlanc no inciso 1
     * http://localhost:8080/{$slug}/export_inciso1/status:1/from:2020-01-01/to:2020-01-30
     *
     * Parâmetros to e from não são obrigatórios, caso não informado retorna todos os registros no status de pendentes
     *
     * Parâmetro status não é obrigatório, caso não informado retorna todos com status 1
     *
     */
    public function ALL_export_inciso1()
    {
        $app = App::i();

        //Oportunidade que a query deve filtrar
        $opportunity_id = $this->config['inciso1_opportunity_id'];
        $opportunity = $app->repo('Opportunity')->find($opportunity_id);

        $this->exportInit($opportunity);
        
        $registrations = $this->getRegistrations($opportunity);

        if (empty($registrations)) {
            echo "Não foram encontrados registros.";
            die();
        }

        $fields = $this->plugin->config['inciso1'];
        
        $filename = $this->generateCSV('inciso1', $registrations, $fields);

        header('Content-Type: application/csv');
        header('Content-Disposition: attachment; filename=' . basename($filename));
        header('Pragma: no-cache');
        readfile($filename);
    }

    /**
     * Exportador para o inciso 2
     *
     * Implementa o sistema de exportação para a lei AldirBlanc no inciso 2
     * http://localhost:8080/{$slug}/export_inciso2/opportunity:6/status:1/type:cpf/from:2020-01-01/to:2020-01-30
     *
     * Parâmetros to e from não são obrigatórios, caso nao informado retorna todos os registros no status de pendentes
     *
     * Parâmetro type se alterna entre cpf e cnpj
     *
     * Parâmetro status não é obrigatório, caso não informado retorna todos com status 1
     *
     */
    public function ALL_export_inciso2()
    {
        $app = App::i();

        $opportunity_id = intval($this->data['opportunity'] ?? 0);
        $opportunity = $app->repo('Opportunity')->find($opportunity_id);

        $this->getRegistrations($opportunity);

        if (empty($registrations)) {
            echo "Não foram encontrados registros.";
            die();
        }

        /**
         * pega as configurações do CSV no arquivo config-csv-inciso2.php
         */
        $csv_conf = $this->config['csv_inciso2'];
        $inscricoes = $this->config['csv_inciso2']['inscricoes_culturais'];
        $atuacoes = $this->config['csv_inciso2']['atuacoes-culturais'];
        $category = $this->config['csv_inciso2']['category'];

        /**
         * Mapeamento de fielsds_id pelo label do campo
         */
        foreach ($opportunity->registrationFieldConfigurations as $field) {
            $field_labelMap["field_" . $field->id] = trim($field->title);
        }
    }

    public function GET_import() {
        $this->requireAuthentication();

        $app = App::i();

        $opportunity_id = $this->data['opportunity'] ?? 0;
        $file_id = $this->data['file'] ?? 0;

        $opportunity = $app->repo('Opportunity')->find($opportunity_id);

        if (!$opportunity) {
            echo "Opportunidade de id $opportunity_id não encontrada";
        }

        $opportunity->checkPermission('@control');

        $plugin = $app->plugins['AldirBlancDataprev'];

        $config = $app->plugins['AldirBlanc']->config;

        $inciso1_opportunity_id = $config['inciso1_opportunity_id'];
        $inciso2_opportunity_ids = $config['inciso2_opportunity_ids'];

        $files = $opportunity->getFiles('dataprev');
        
        foreach ($files as $file) {
            if ($file->id == $file_id) {
                if($opportunity_id == $inciso1_opportunity_id){
                    $this->import_inciso1($opportunity, $file->getPath());
                } else if (in_array($opportunity_id, $inciso2_opportunity_ids)) {
                    $this->import_inciso2($opportunity, $file->getPath());
                }
            }
        }
    }

    /**
     * Importador para o inciso 1
     *
     * Implementa o sistema de importação dos dados da dataprev para a lei AldirBlanc no inciso 1
     * http://localhost:8080/dataprev/import_inciso1/
     *
     * Parametros to e from não são obrigatórios, caso nao informado retorna os últimos 7 dias de registros
     *
     * Paramentro type se alterna entre cpf e cnpj
     *
     * Paramentro status não é obrigatorio, caso não informado retorna todos com status 1
     *
     */
    public function import_inciso1(Opportunity $opportunity, string $filename)
    {

        /**
         * Seta o timeout e limite de memoria
         */
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '768M');

        // Pega as configurações no arquivo config-csv-inciso1.php
        $conf_csv = $this->config['csv_inciso1'];

        //verifica se o mesmo esta no servidor
        if (!file_exists($filename)) {
            throw new Exception("Erro ao processar o arquivo. Arquivo inexistente");
        }

        $app = App::i();

        //Abre o arquivo em modo de leitura
        $stream = fopen($filename, "r");

        //Faz a leitura do arquivo
        $csv = Reader::createFromStream($stream);

        //Define o limitador do arqivo (, ou ;)
        $csv->setDelimiter(";");

        //Seta em que linha deve se iniciar a leitura
        $header_temp = $csv->setHeaderOffset(0);

        //Faz o processamento dos dados
        $stmt = (new Statement());
        $results = $stmt->process($csv);

        //Verifica a extenção do arquivo
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        if ($ext != "csv") {
            throw new Exception("Arquivo não permitido.");
        }

        //Verifica se o arquivo esta dentro layout
        foreach ($header_temp as $key => $value) {
            $header_file[] = $value;
            break;

        }

        foreach ($header_file[0] as $key => $value) {
            $header_line_csv[] = $key;

        }

        //Verifica se o layout do arquivo esta nos padroes enviados pela dataprev
        $herder_layout = $conf_csv['herder_layout'];

        if ($error_layout = array_diff_assoc($herder_layout, $header_line_csv)) {
            throw new Exception("os campos " . json_encode($error_layout) . " estão divergentes do layout necessário.");

        }

        //Inicia a verificação dos dados do requerente
        $evaluation = [];
        $parameters = $conf_csv['acceptance_parameters'];
        
        $registrat_ids = [];

        foreach ($results as $results_key => $item) {
            $registrat_ids[] = $item['IDENTIF_CAD_ESTAD_CULT'];
        }

        $dql = "
        SELECT
            e.number,
            e._agentsData
        FROM
            MapasCulturais\Entities\Registration e
        WHERE
            e.number in (:reg_ids)";

        $query = $app->em->createQuery($dql);
        $query->setParameters([
            'reg_ids' => $registrat_ids
        ]);

        $agent_names = [];

        foreach($query->getScalarResult() as $r) {
            $data = json_decode($r['_agentsData']);
            $agent_names[$r['number']] = $data->owner->nomeCompleto;
        };
        $raw_data_by_num = [];
        // return;
        foreach ($results as $results_key => $result) {
            $raw_data_by_num[$result['IDENTIF_CAD_ESTAD_CULT']] = $result;
            
            $candidate = $result;
            foreach ($candidate as $key_candidate => $value) {
                if(in_array($key_candidate, $conf_csv['validation_cad_cultural'])) {
                    continue;
                }

                if ($key_candidate == 'IDENTIF_CAD_ESTAD_CULT') {
                    $evaluation[$results_key]['DADOS_DO_REQUERENTE']['N_INSCRICAO'] = $value;
                }

                if ($key_candidate == 'REQUERENTE_CPF') {
                    $evaluation[$results_key]['DADOS_DO_REQUERENTE']['CPF'] = $value;
                }

                $field = isset($parameters[$key_candidate]) ? $parameters[$key_candidate] : "";
                
                if (is_array($field)) {

                    if ($key_candidate == "REQUERENTE_DATA_NASCIMENTO") {
                        $date = explode("/", $value);
                        $date = new DateTime($date[2] . '-' . $date[1] . '-' . $date[0]);
                        $idade = $date->diff(new DateTime(date('Y-m-d')));

                        if ($idade->format('%Y') >= $field['positive'][0]) {
                            $evaluation[$results_key]['VALIDATION'][$key_candidate] = true;

                        } else {
                            $evaluation[$results_key]['VALIDATION'][$key_candidate] = $field['response'];
                        }

                    }elseif ($key_candidate == "SITUACAO_CADASTRO") {

                        if (in_array(trim($value), $field['positive'])) {
                            $evaluation[$results_key]['VALIDATION']['SITUACAO_CADASTRO'] = true;

                        } elseif (in_array(trim($value), $field['negative'])) {
                            if(is_array($field['response'])){
                                $evaluation[$results_key]['VALIDATION']['SITUACAO_CADASTRO'] = $field['response'][$value];

                            }else{
                                $evaluation[$results_key]['VALIDATION']['SITUACAO_CADASTRO'] = $field['response'];
                                
                            }
                            

                        }

                    // A validação de cadastro cultural não é necessária pq o mapas é um cadastro válido 
                    // }elseif (in_array($key_candidate,  $conf_csv['validation_cad_cultural'] )){
                        
                    //     if (in_array(trim($value), $field['positive'])) {
                    //         $evaluation[$results_key]['VALIDATION']['VALIDA_CAD_CULTURAL'] = true;

                    //     } elseif (in_array(trim($value), $field['negative'])) {
                    //         $evaluation[$results_key]['VALIDATION']['VALIDA_CAD_CULTURAL'] = $field['response'];

                    //     }
                      
                    }else {

                        if (in_array(trim($value), $field['positive'])) {
                            $evaluation[$results_key]['VALIDATION'][$key_candidate] = true;

                        } elseif (in_array(trim($value), $field['negative'])) {
                            $evaluation[$results_key]['VALIDATION'][$key_candidate] = $field['response'];

                        }

                    }

                } else {

                    if ($field) {
                        if ($value === $field) {
                            $evaluation[$results_key]['VALIDATION'][$key_candidate] = true;
                        }

                    }

                }

            }

        }
        
        //Define se o requerente esta apto ou inapto levando em consideração as diretrizes de negocio
        $result_aptUnfit = [];       
        foreach ($evaluation as $key_evaluetion => $value) {
            $result_validation = array_diff($value['VALIDATION'], $conf_csv['validation_reference']);
            if (!$result_validation) {
                $result_aptUnfit[$key_evaluetion] = $value['DADOS_DO_REQUERENTE'];
                $result_aptUnfit[$key_evaluetion]['ACCEPT'] = true;
            } else {
                $result_aptUnfit[$key_evaluetion] = $value['DADOS_DO_REQUERENTE'];                
                $result_aptUnfit[$key_evaluetion]['ACCEPT'] = false;
                foreach ($value['VALIDATION'] as $value) {
                    if (is_string($value)) {
                        $result_aptUnfit[$key_evaluetion]['REASONS'][] = $value;
                    }
                }
            }

        }
        $aprovados = array_values(array_filter($result_aptUnfit, function($item) {
            if($item['ACCEPT']) {
                return $item;
            }
        }));

        $reprovados = array_values(array_filter($result_aptUnfit, function($item) {
            if(!$item['ACCEPT']) {
                return $item;
            }
        }));

        $app->disableAccessControl();
        $count = 0;
        
        foreach($aprovados as $r) {
            $count++;
            
            $registration = $app->repo('Registration')->findOneBy(['number' => $r['N_INSCRICAO']]);
            $registration->__skipQueuingPCacheRecreation = true;
            
            /* @TODO: implementar atualização de status?? */
            if ($registration->dataprev_raw != (object) []) {
                $app->log->info("Dataprev #{$count} {$registration} APROVADA - JÁ PROCESSADA");
                continue;
            }
            
            $app->log->info("Dataprev #{$count} {$registration} APROVADA");
            
            $registration->dataprev_raw = $raw_data_by_num[$registration->number];
            $registration->dataprev_processed = $r;
            $registration->dataprev_filename = $filename;
            $registration->save(true);
    
            $user = $app->plugins['AldirBlancDataprev']->getUser();

            /* @TODO: versão para avaliação documental */
            $evaluation = new RegistrationEvaluation;
            $evaluation->__skipQueuingPCacheRecreation = true;
            $evaluation->user = $user;
            $evaluation->registration = $registration;
            $evaluation->evaluationData = ['status' => "10", "obs" => 'selecionada'];
            $evaluation->result = "10";
            $evaluation->status = 1;

            $evaluation->save(true);

            $app->em->clear();

        }

        foreach($reprovados as $r) {
            $count++;

            $registration = $app->repo('Registration')->findOneBy(['number' => $r['N_INSCRICAO']]);
            $registration->__skipQueuingPCacheRecreation = true;
            
            if ($registration->dataprev_raw != (object) []) {
                $app->log->info("Dataprev #{$count} {$registration} REPROVADA - JÁ PROCESSADA");
                continue;
            }

            $app->log->info("Dataprev #{$count} {$registration} REPROVADA");

            $registration->dataprev_raw = $raw_data_by_num[$registration->number];
            $registration->dataprev_processed = $r;
            $registration->dataprev_filename = $filename;
            $registration->save(true);

            $user = $app->plugins['AldirBlancDataprev']->getUser();

            /* @TODO: versão para avaliação documental */
            $evaluation = new RegistrationEvaluation;
            $evaluation->__skipQueuingPCacheRecreation = true;
            $evaluation->user = $user;
            $evaluation->registration = $registration;
            $evaluation->evaluationData = ['status' => "2", "obs" => implode("\\n", $r['REASONS'])];
            $evaluation->result = "2";
            $evaluation->status = 1;

            $evaluation->save(true); 

            $app->em->clear();

        }
        
        // por causa do $app->em->clear(); não é possível mais utilizar a entidade para salvar
        $opportunity = $app->repo('Opportunity')->find($opportunity->id);
        
        $opportunity->refresh();
        $opportunity->name = $opportunity->name . ' ';
        $files = $opportunity->dataprev_processed_files;
        $files->{basename($filename)} = date('d/m/Y \à\s H:i');
        $opportunity->dataprev_processed_files = $files;
        $opportunity->save(true);
        $app->enableAccessControl();
        $this->finish('ok');
    }

    public function import_inciso2() {
        
    }
}
