{{--
    Aviso de "as opções não carregaram" dos campos que leem do banco
    (dbcombo/dbselect, dbradio, dbcheckbox-group, dbselect-check, dbsort-list,
    dbchecklist, buscas dbunique/dbmulti/dbentry, db-steps).

    $optionsError vem de \Mad\Form\OptionsLoadError::handle()/handleMissing():
    null sem erro OU com APP_DEBUG desligado — aí nada é emitido e o campo fica
    byte a byte como antes. Com debug, a linha fica visível sem abrir a lista
    (o MAD Select esconde <option value="">, então a option desabilitada do
    select sozinha não aparece na lista suspensa).
--}}
@if(!empty($optionsError))
    <p class="mad-field-hint mad-error" data-mad-options-error role="alert">{{ $optionsError }}</p>
@endif
