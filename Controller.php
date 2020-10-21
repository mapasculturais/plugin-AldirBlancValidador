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
class Controller extends \MapasCulturais\Controllers\Registration
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

        $dql_from = '';
        if ($from) {
            //Data ínicial
            $dql_params['from'] (new DateTime($from))->format('Y-m-d 00:00');
            $dql_from = "e.sentTimestamp >= :from AND";
        }

        $dql_to = '';
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
        $headers =  array_merge(['NUMERO'], array_keys($fields), ['AVALIACAO', 'OBSERVACOES']);
        
        $csv_data = [];

        foreach ($registrations as $i => $registration) {
            $csv_data[$i] = [
                'NUMERO' => $registration->number
            ];

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

                $csv_data[$i][$key] = $value;
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
        $csv->setDelimiter(";");

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

        $config = $app->plugins['AldirBlanc']->config;

        $inciso1_opportunity_id = $config['inciso1_opportunity_id'];
        $inciso2_opportunity_ids = $config['inciso2_opportunity_ids'];

        $files = $opportunity->getFiles($this->plugin->getSlug());
        
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
     * http://localhost:8080/{slug}/import_inciso1/
     *
     */
    public function import_inciso1(Opportunity $opportunity, string $filename)
    {

        /**
         * Seta o timeout e limite de memoria
         */
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '768M');


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
        // $csv->setDelimiter(";");

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
        
        $header_file = [];
        foreach ($header_temp as $key => $value) {
            $header_file[] = $value;
            break;
        }

        if(!isset($header_file[0]['NUMERO']) || !isset($header_file[0]['AVALIACAO']) || !isset($header_file[0]['OBSERVACOES'])) {
            die('As colunas NUMERO, AVALIACAO e OBSERVACOES são obrigatórias');
        }

        $slug = $this->plugin->slug;
        $name = $this->plugin->name;
        
        $app->disableAccessControl();
        $count = 0;
        foreach ($results as $i => $line) {
            $num = $line['NUMERO'];
            $obs = $line['OBSERVACOES'];
            $eval = $line['AVALIACAO'];

            switch(strtolower($line['AVALIACAO'])){
                case 'selecionado':
                case 'selecionada':
                    $result = '10';
                break;

                case 'invalido':
                case 'inválido':
                case 'invalida':
                case 'inválida':
                    $result = '2';
                break;

                case 'não selecionado':
                case 'nao selecionado':
                case 'não selecionada':
                case 'nao selecionada':
                    $result = '3';
                break;
                
                case 'suplente':
                    $result = '8';
                break;
                
                default:
                    die("O valor da coluna AVALIACAO da linha $i está incorreto. Os valores possíveis são 'selecionada', 'invalida', 'nao selecionada' ou 'suplente'");
                
            }
            
            $registration = $app->repo('Registration')->findOneBy(['number' => $num]);
            $registration->__skipQueuingPCacheRecreation = true;
            
            /* @TODO: implementar atualização de status?? */
            if ($registration->{$slug . '_raw'} != (object) []) {
                $app->log->info("$name #{$count} {$registration} $eval - JÁ PROCESSADA");
                continue;
            }
            
            $app->log->info("$name #{$count} {$registration} $eval");
            
            $registration->{$slug . '_raw'} = $line;
            $registration->{$slug . '_filename'} = $filename;
            $registration->save(true);
    
            $user = $this->plugin->user;

            /* @TODO: versão para avaliação documental */
            $evaluation = new RegistrationEvaluation;
            $evaluation->__skipQueuingPCacheRecreation = true;
            $evaluation->user = $user;
            $evaluation->registration = $registration;
            $evaluation->evaluationData = ['status' => $result, "obs" => $obs];
            $evaluation->result = $result;
            $evaluation->status = 1;

            $evaluation->save(true);

            $app->em->clear();
        }

        $app->enableAccessControl();

        // por causa do $app->em->clear(); não é possível mais utilizar a entidade para salvar
        $opportunity = $app->repo('Opportunity')->find($opportunity->id);

        $slug = $this->plugin->getSlug();

        $opportunity->refresh();
        $opportunity->name = $opportunity->name . ' ';
        $files = $opportunity->{$slug . '_processed_files'};
        $files->{basename($filename)} = date('d/m/Y \à\s H:i');
        $opportunity->{$slug . '_processed_files'} = $files;
        $opportunity->save(true);
        $this->finish('ok');
        

    }

    public function import_inciso2() {
        
    }
}
