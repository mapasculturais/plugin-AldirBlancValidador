<?php 
use MapasCulturais\i;

$app = MapasCulturais\App::i();

$slug = $plugin_validador->getSlug();
$name = $plugin_validador->getName();

if ($inciso == 1){
    $route = MapasCulturais\App::i()->createUrl($slug, 'export_inciso1');    
    ?>
    
    <a class="btn btn-default download btn-export-cancel"  ng-click="editbox.open('export-inciso1', $event)" rel="noopener noreferrer">CSV <?= $name ?></a>

    <!-- Formulário -->
    <edit-box id="export-inciso1" position="top" title="<?php i::esc_attr_e('Exportar CSV Inciso I') ?>" cancel-label="Cancelar" close-on-cancel="true">
        <form class="form-export-<?= $slug ?>" action="<?=$route?>" method="POST">
      
            <label for="from">Data inícial</label>
            <input type="date" name="from" id="from">
            
            <label for="from">Data final</label>  
            <input type="date" name="to" id="to">

            # Caso não queira filtrar entre datas, deixe os campos vazios.
            <button class="btn btn-primary download" type="submit">Exportar</button>
        </form>
    </edit-box>

    <?php
}
else if ($inciso ==2){
    $route = MapasCulturais\App::i()->createUrl($slug, 'export_inciso2');   
    ?>
    <a class="btn btn-default download form-export-clear" ng-click="editbox.open('export-inciso2', $event)" rel="noopener noreferrer">CSV <?= $name ?></a>
    
    <!-- Formulario para cpf -->
    <edit-box id="export-inciso2" position="top" title="<?php i::esc_attr_e('Exportar CSV Inciso II') ?>" cancel-label="Cancelar" close-on-cancel="true">
        <form class="form-export-<?= $slug ?>" action="<?=$route?>" method="POST">
      
            <label for="from">Data inícial</label>
            <input type="date" name="from" id="from">
            
            <label for="from">Data final</label>  
            <input type="date" name="to" id="to">            

            <label for="type">Tipo de exportação (CPF ou CNPJ)</label>
            <select name="type" id="type">
                <option value="cpf">Pessoa física (CPF)</option>
                <option value="cnpj">Pessoa jurídica (CNPJ)</option>
            </select>

            <input type="hidden" name="opportunity" value="<?=$opportunity?>">

            # Caso não queira filtrar entre datas, deixe os campos vazios.
            <button class="btn btn-primary download" type="submit">Exportar</button>            
        </form>
    </edit-box>

    
    <?php
}

?>
