{{--
    mad-dbselect-field — ALIAS de mad-dbcombo-field (componente unificado).

    Historicamente dbselect era o select NATIVO (sem busca) e dbcombo o select
    com busca ativa. Apos a unificacao os dois sao o MESMO componente: select com
    busca ativa (MAD Select), auto-query do banco, depends-on (cascata) e
    no-results (cadastrar/quick-register no dropdown).

    Este arquivo nao tem logica propria — apenas encaminha para o dbcombo-field,
    preservando a tag <mad-dbselect-field> por compatibilidade com:
      - templates existentes (resources/views/teste/*, etc.)
      - MadDashFiltersCompiler (TAG_TYPES: 'mad-dbselect-field' => 'dbselect')
      - MadQuickFormCompiler (aceita quick-form dentro de <mad-dbselect-field>)

    @include herda todas as variaveis do escopo (atributos ja viraram vars no
    compilador MAD), entao todas as props chegam intactas no dbcombo-field.
--}}
@include('components.dbcombo-field')
