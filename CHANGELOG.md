# Novidades — Mad Framework

Mudanças do runtime dos apps gerados pelo MadBuilder, escritas para quem
constrói telas com as tags `<mad-*>`.

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/).
A partir do **5.0.0** (jul/2026): semver com fonte da verdade em
`packages/mad-framework/VERSION` — toda mudança em `packages/mad-framework/**`
bumpa a versão no mesmo commit (regra em `.claude/rules/framework-versioning.md`).
Uma entrada por versão, com até quatro seções — Novidades, Melhorias, Correções e
Atenção — e cada item em uma linha: `- **Área:** o que mudou para você.`
Detalhe técnico fica no commit e no PR; o histórico antigo, técnico, está
congelado em `docs/changelog-tecnico.md`.

## [5.140.0] — 2026-10-09

### Novidades

- **Layout:** o `<mad-separator>` ganhou aparência própria: `color` (cor da linha), `thickness` (espessura em px), `line-style` (`solid`, `dashed`, `dotted` ou `double`) e `spacing` (espaço acima e abaixo). Sem as props nada muda: linha fina contínua na cor da borda do tema.
- **Layout:** no separador com texto, `label-align="left"` ou `"right"` encosta o texto num dos lados; o padrão continua centralizado.

## [5.139.1] — 2026-10-09

### Correções

- **Formulários:** o "Valor padrão" definido no Studio não aparecia ao abrir um cadastro novo em vários campos (texto, número, spinner, seleção, rádio, interruptor e combos de tabela). Na edição continua valendo o valor gravado. Republique o projeto para aplicar.

## [5.139.0] — 2026-10-09

### Novidades

- **Formulários:** `label-position="right"` no `<mad-switch-field>` põe a chave primeiro e o texto logo depois, juntos. Sem a prop nada muda: texto à esquerda e chave na ponta direita da coluna.
- **Formulários:** `valign` no `<mad-form-grid>` alinha os campos da linha na vertical: `top`, `center` ou `bottom`. Serve para um switch ou checkbox ao lado de um campo com label, que ficava sempre no topo da coluna.

## [5.138.0] — 2026-10-08

### Novidades

- **Formulários:** o combo com valor ganhou um × ao lado da seta: um clique limpa o campo sem abrir a lista. Aparece quando o campo pode ficar vazio (tem a opção em branco e não é obrigatório). Com o foco no campo, Delete faz o mesmo.

### Correções

- **Formulários:** no Firefox, o primeiro clique no combo abria a lista sem o cursor na busca; era preciso clicar de novo para digitar.

## [5.137.0] — 2026-10-08

### Novidades

- **Formulários:** o combo aceita `no-search`, que abre a lista sem a caixa de busca. Vale no `<mad-select-field>` (também com `multiple`) e no `<mad-dbcombo-field>`; setas, Enter e a letra inicial continuam escolhendo o item.
- **Formulários:** `allow-empty` no `<mad-select-field>` põe a opção em branco no topo da lista sem precisar de `placeholder`. No `<mad-dbcombo-field>`, `no-empty` tira essa opção e o campo já abre no primeiro registro.

### Correções

- **Formulários:** depois de escolher um item no combo não havia como deixar o campo vazio de novo. A opção em branco voltou a aparecer no topo da lista, menos em campo obrigatório.
- **Formulários:** o combo vazio mostrava sempre "Selecione...", mesmo com outro texto no `placeholder`.

## [5.136.5] — 2026-10-08

### Novidades

- **E-mail:** a aba E-mail das Preferências ganhou o botão **Enviar e-mail de teste**: envia na hora com o que está na tela, antes de salvar, e mostra a resposta do servidor quando ele recusa.
- **E-mail:** a aba E-mail das Preferências mostra no topo por onde os e-mails do app estão saindo (servidor da tela, configuração do servidor ou envio desligado) e lista os últimos e-mails que não saíram.
- **Editor HTML:** comando `php artisan mad:html-clean <Model> <coluna> [--dry-run]` para limpar o conteúdo de Editor HTML que já está gravado. Só regrava o registro em que há algo a tirar.
- **Formulários:** `screen-only` na tag do campo, ou `$this->form->screenOnly('campo')` no `mount()`, declara um campo só de tela: o Salvar não o grava nem avisa.
- **Lista de itens:** `saveDetailItems()` aceita `only: [...]` (as colunas que copia de cada linha) e `fieldList:` (o nome da lista, quando a tela tem duas sobre a mesma tabela).
- **Listagens:** a Ação Grid (`<mad-act>`) passa a aceitar **Tooltip** (`title`), **Cor custom** (`color`), **Campo ID** (`id-field`) e **Parâmetros** (`params="{chave: valor, outra: {campo}}"` ou `:params`). Os parâmetros chegam ao método depois do id, como lista, na ordem declarada e com `{campo}` trocado pelo valor da linha: `public function onAprovar($id, array $extras = [])`.
- **Listagens:** o `<mad-del>` aceita as mesmas condições do `<mad-act>`: `display-condition`, `when-field` com `when-value`/`when-in`/`when-nin` e `disabled-field`. O `<mad-nav>` também passa a aceitar `when-*` e `disabled-*`.
- **Listagens:** a ação em lote que abre outra tela (`<mad-bulk-action target="…">`) segue a permissão da tela de destino, como o `<mad-nav>`.
- **Calendário:** `SuaAgenda::visibleEvent($id)` devolve o evento só quando a agenda o mostra a quem está logado. Use no formulário dos eventos para conferir a chave antes de abrir, salvar ou excluir.

### Melhorias

- **Código:** `MadUserError::message($e)` passa a devolver uma frase específica para as recusas mais comuns do banco (valor repetido, campo obrigatório, registro em uso, texto maior do que o permitido, banco ocupado) e "Registro não encontrado." para `findOrFail()`. Em tela sem login use `MadUserError::publicMessage($e)`, que nunca diz qual foi a recusa.
- **Teste Online:** quando uma tela falha ao abrir, o app avisa o teste de tela do agente de IA, que deixa de tratar a tela como funcionando. O aviso só existe dentro do Teste Online: o app publicado responde como antes.
- **Teste Online:** o aviso de erro enviado ao teste de tela não leva mais valores de consultas ao banco, caminhos do servidor nem senhas.
- **Listagens:** ação mal configurada deixa de sumir em silêncio. Com o modo de depuração ligado, uma faixa acima da grade diz qual ação e por quê: `<mad-act>` sem **On Click** nem **Navigate**, `method` e `navigate` juntos (vale o método), `drawer`/`row` sem `navigate`, tag que o grupo de ações não aceita, grupo sem ações. O mesmo texto vai para o log.
- **Listagens:** a célula que está sendo gravada fica esmaecida quando o servidor demora a responder.

### Correções

- **Segurança:** o registro aberto num formulário não pode mais ser trocado pelo navegador ao salvar ou excluir. Uma requisição adulterada gravava em outro registro e nas linhas dele, mesmo com trava de dono no `onEdit`. Republique o projeto para aplicar.
- **Segurança:** o passo do assistente (wizard) deixou de aceitar valor vindo do navegador, que permitia pular a validação de uma etapa, e um perfil sem **Incluir** não cria mais registro por requisição adulterada.
- **Segurança:** a verificação em duas etapas, a troca obrigatória de senha e o aceite dos termos não podem mais ser pulados por uma requisição adulterada no login. Republique o projeto para aplicar.
- **Segurança:** em app com mais de uma unidade ou empresa, um DB Combo, busca ou listagem sobre a tabela de usuários mostrava os usuários de todas as unidades e empresas. Agora mostra só os das unidades de quem está logado. Republique o projeto para aplicar.
- **Segurança:** a cortina Perfis e módulos do cadastro de usuários grava sempre no usuário e na unidade da linha que foi aberta; uma requisição adulterada não troca mais esse par. Republique o projeto para aplicar.
- **Segurança:** quem tinha acesso a um formulário conseguia gravar, com uma requisição adulterada, colunas do registro que a tela não tem (o status, o saldo, a senha). Agora o formulário só aceita do navegador os campos que a tela tem; o resto é ignorado e registrado no log do app. Vale para formulário em página, cortina lateral, janela, registro único, wizard e cadastro novo. Republique o projeto para aplicar.
- **Segurança:** na Lista de itens e no Detail Form, a mesma requisição gravava colunas da linha que a grade não mostra. Agora, de cada linha, só são aceitas as colunas da grade e do editor.
- **Segurança:** o cadastro rápido do combo (**Cadastrar novo**) aceitava colunas a mais na requisição. Agora só aceita o texto digitado e os campos configurados no combo.
- **Segurança:** a chave gerada pelo banco, as colunas de data e autor de criação, alteração e exclusão e a empresa do registro não são mais gravadas a partir da tela, nem quando existe um campo com esse nome.
- **Segurança:** quem tinha acesso a uma listagem conseguia, com uma requisição adulterada, derrubar o filtro fixo dela (o que restringe as linhas a um dono, a uma unidade ou a uma situação), trocar a tabela consultada e fazer a busca procurar em colunas que a tela não mostra. As ações de filtro passam a aceitar só os filtros que a tela tem e o período; a tentativa com outro nome é ignorada e registrada no log do app. Vale também para Kanban, calendário e dashboard. Republique o projeto para aplicar.
- **Segurança:** a senha do servidor de e-mail das Preferências era gravada em texto puro no banco. Agora fica cifrada; a que já estava gravada é cifrada na atualização.
- **Segurança:** quando o banco recusava uma gravação (banco ocupado, valor repetido, campo obrigatório), as telas de Usuários, cadastro, login, redefinição de senha e instalação mostravam a mensagem técnica inteira: a instrução SQL com os dados digitados, inclusive a senha já criptografada. Agora aparece uma frase que diz o que fazer ("O campo E-mail já está sendo utilizado.", "O sistema está ocupado no momento. Aguarde alguns segundos e tente de novo.") e o detalhe vai só para o log do app, sem a senha. Vale também para as demais telas de administração. Republique o projeto para aplicar.
- **Segurança:** o log do app e as telas de erro do modo de desenvolvimento não gravam nem mostram mais o valor de senha, token ou chave quando registram um erro do banco.
- **Segurança:** o conteúdo de um campo Editor HTML podia ser gravado com script quando a requisição do Salvar era adulterada, e esse script rodava no navegador de quem abrisse a tela que mostra o texto. Agora o conteúdo é limpo sempre que chega ao campo: em formulário de página, cortina lateral, janela, wizard (inclusive no **Avançar** que grava a cada etapa) e nas linhas de um Detail Form. Republique o projeto para aplicar.
- **Segurança:** o Editor HTML e a coluna `html` da listagem (`<mad-col html />`) passam a limpar o conteúdo que vem do banco antes de mostrá-lo. Conteúdo gravado antes desta versão deixa de ser executado nesses dois lugares.
- **Segurança:** o Salvar das Preferências também deixou de mostrar a mensagem técnica do banco quando falha.
- **Segurança:** as chaves de API de IA (OpenRouter e Anthropic) e a chave secreta do reCAPTCHA das Preferências eram gravadas em texto puro no banco, e a do reCAPTCHA voltava preenchida para a tela. Agora ficam cifradas e o campo vem sempre vazio: em branco, o Salvar mantém a que está gravada. O que já estava gravado continua funcionando e é cifrado na atualização ou no primeiro Salvar das Preferências. Republique o projeto para aplicar.
- **Segurança:** a tela **PHP Info** mostrava as variáveis do servidor, entre elas a chave do app e as senhas de banco e de e-mail. Agora mostra só a versão, a configuração e os módulos do PHP.
- **Segurança:** a tela **Session dump** mostrava os tokens do Copilot e da Central de Comando e o código de verificação. Agora mostra que a variável existe, sem o valor.
- **Segurança:** os e-mails que aguardam na fila (redefinição de senha, convite, código de verificação) ficavam legíveis no banco. Agora ficam cifrados até o envio.
- **Segurança:** a semente do aplicativo autenticador (verificação em duas etapas) ficava em texto puro no banco: quem lesse um backup gerava os códigos de qualquer usuário. Agora fica cifrada. As sementes já gravadas continuam funcionando e são cifradas na atualização ou no próximo login com o código. Republique o projeto para aplicar.
- **Segurança:** o código de verificação enviado por e-mail ficava legível na sessão gravada no banco. Agora fica só a impressão digital dele.
- **Segurança:** o Upload Múltiplo em outra tabela, a seleção múltipla em outra tabela e os campos de arquivo gravavam com as definições que o navegador devolvia junto do formulário. Com o formulário de outra tela do mesmo app, uma requisição adulterada gravava e apagava anexos e ligações em uma tabela que a tela não usa. Agora cada tela só grava os campos que ela mesma desenhou; o resto é ignorado e registrado no log do app. Republique o projeto para aplicar.
- **Segurança:** em Kanban, organograma, PDV, conciliação e planilha, uma requisição adulterada conseguia trocar a configuração da tela pela de outra tela aberta na mesma sessão. A chave dessa configuração deixou de aceitar valor enviado pelo navegador.
- **Segurança:** num app com Multi-unidade, a seleção múltipla gravada por vírgula na própria coluna (grupo de caixas, select com caixas, multi busca e checklist sobre uma tabela) aceitava, numa requisição alterada, o item de outra unidade ou o usuário que quem salva não enxerga. Agora o Salvar é recusado, com a mensagem no campo; o que já estava gravado continua valendo. Republique o projeto para aplicar.
- **Segurança:** os arquivos das linhas de uma Lista de itens ou de um Detail Form eram gravados com as definições que o navegador devolvia junto do formulário. Com o formulário de outra tela do mesmo app, uma requisição adulterada mandava o arquivo para outra pasta ou outra tabela. Agora cada tela só grava os arquivos das linhas como ela mesma os desenhou. Republique o projeto para aplicar.
- **Agente de IA:** o teste de tela volta a ser avisado quando uma tela do Teste Online quebra ao abrir, em vez de seguir como se ela tivesse carregado. Publique o projeto de novo no Teste Online para aplicar.
- **Formulários:** ao salvar uma Lista de itens com uma linha nova acima das existentes (arrastada para o topo), as outras linhas eram apagadas e recriadas com outros códigos. Agora só a linha nova é criada e as demais são atualizadas. Republique o projeto para aplicar.
- **Formulários:** a linha removida de uma Lista de itens ou de um Detail Form passa a respeitar a exclusão lógica da tabela e as regras do Model (auditoria, regra que impede a exclusão).
- **Formulários:** quando as linhas de uma Lista de itens ou de um Detail Form não carregavam ao abrir o registro, o Salvar apagava as que já existiam. Agora ele só remove a linha que a tela mostrou e o usuário tirou.
- **Formulários:** com o mesmo registro aberto em duas abas (ou por duas pessoas), a linha ou o anexo que uma removeu voltava quando a outra salvava. Agora não volta, e quem salva é avisado de que o item já tinha sido removido.
- **Formulários:** anexos enviados pelo Upload Múltiplo em modo tabela eram apagados ao salvar quando a mesma tabela também aparecia na tela como Detail Form ou Lista de itens. Agora são mantidos, e o Upload não apaga anexo que não chegou a mostrar.
- **Formulários:** nos campos de seleção múltipla gravados em outra tabela (Checkbox Group, Select com caixas, Multi Search e Checklist, em `mode="table"`), salvar o registro apagava e recriava todas as ligações: as colunas extras da tabela de ligação voltavam vazias e as ligações trocavam de código. Agora só a ligação que mudou é gravada. Republique o projeto para aplicar.
- **Formulários:** quando as marcas ou as opções de um desses campos não carregavam ao abrir o registro, o Salvar apagava todas as ligações. Agora ele só desmarca o que a tela mostrou marcado e o usuário desmarcou.
- **Formulários:** com a lista de opções filtrada, salvar o registro apagava a ligação com um item que a lista não mostrava. Essa ligação agora é mantida.
- **Formulários:** depois que uma ação redesenhava a tela, os campos de seleção múltipla voltavam sem nenhuma marca, e o Salvar seguinte gravava a seleção vazia. Agora as marcas são mantidas.
- **Formulários:** com o mesmo registro aberto em duas abas (ou por duas pessoas), a opção que uma desmarcava voltava quando a outra salvava, e a que uma marcava sumia. Agora a aba desatualizada não desfaz o que a outra fez, e quem salva é avisado.
- **Formulários:** na Lista de itens com coluna Arquivos (`type="files"`), quando os arquivos de uma linha não carregavam ao abrir o registro, o Salvar apagava todos. Agora só é apagado o arquivo que a célula mostrou e o usuário removeu.
- **Formulários:** a coluna Arquivos da Lista de itens abria sempre vazia, e o Salvar apagava os arquivos da linha, quando a tabela dos arquivos não tinha a coluna `storage` ou quando a chave da tabela de itens não se chamava `id`. Agora os arquivos aparecem e são mantidos.
- **Formulários:** com duas abas, o arquivo que uma anexava numa linha da lista sumia quando a outra salvava. Agora é mantido.
- **Formulários:** na lista de marcar com busca (`<mad-checklist-field>` e `<mad-dbchecklist-field>`), salvar com texto na busca ou com o filtro "somente selecionados" ligado desmarcava todos os itens que a busca escondia, sem aviso. Agora a busca só esconde: o que estava marcado continua marcado. Republique o projeto para aplicar.
- **Formulários:** nos campos de marcar várias opções gravados na própria coluna, por vírgula (Checklist, Checkbox Group, Select com caixas e Multi Search, com opções fixas ou de banco), salvar o registro tirava da coluna o item marcado que a lista não mostrava: opção inativa, lista filtrada ou opção que não carregou. Esse item agora é mantido. Republique o projeto para aplicar.
- **Formulários:** o `<mad-dbcheckbox-group-field>` gravado por vírgula abria sem nenhuma opção marcada, e salvar o registro apagava a seleção. Agora ele abre com o que está gravado.
- **Formulários:** com o mesmo registro aberto em duas abas (ou por duas pessoas), nos campos gravados por vírgula a opção que uma marcava sumia quando a outra salvava, e a que uma desmarcava voltava. Agora a aba desatualizada não desfaz o que a outra fez, e quem salva é avisado.
- **Formulários:** salvar um registro devolvia, para toda coluna sem campo na tela, o valor de quando a tela foi aberta. Se outra tela, outra pessoa ou outro processo tivesse mudado o status, o saldo ou uma data nesse intervalo, a mudança era desfeita sem aviso. Agora o Salvar grava só os campos que a tela tem e o que o código da tela atribui. Vale para formulário em página, cortina lateral, janela, registro único e wizard. Republique o projeto para aplicar.
- **Formulários:** na Lista de itens e no Detail Form, as colunas da linha que a grade não mostra também voltavam ao valor de quando a tela abriu. Agora só são gravadas as colunas que a grade ou o editor têm, a não ser que o código da tela mude o valor.
- **Formulários:** coluna de data e hora com `casts` no Model e sem campo na tela mudava de hora a cada Salvar em apps com fuso diferente de UTC. Agora ela não é mais tocada.
- **Formulários:** quando um campo de tabela apontava para um registro que quem edita não enxerga (usuário de outra unidade, item inativo ou excluído), o campo abria em "Selecione..." e o Salvar apagava o valor. Agora o valor fica, mostrado como "Registro atual (fora da sua lista)", sem revelar o nome. Republique o projeto para aplicar.
- **Formulários:** o Salvar também apagava o valor de um DB Combo, DB Select ou DB Radio quando a lista de opções não carregava. Agora o valor fica.
- **Formulários:** `<mad-dbradio-field>` abria o registro sem opção marcada e o Salvar gravava a última opção da lista. Agora abre na opção gravada e grava a marcada.
- **Formulários:** o que o usuário digitava num campo que não é coluna da tabela era descartado e a tela respondia "salvo com sucesso". Agora o campo gerado pela plataforma que ficou sem coluna recusa o Salvar, com a mensagem no campo, e nos demais casos a tela avisa o que não foi gravado.
- **Formulários:** o campo Upload (`<mad-file-field>`) não conferia o **Tamanho máximo** nem os **Tipos aceitos**, e arrastar e soltar aceitava qualquer arquivo. Agora os dois valem ao escolher e ao arrastar (com aviso na hora) e no Salvar (erro no campo, nada é gravado). A mensagem diz o tamanho do arquivo e o limite.
- **Formulários:** o Upload Múltiplo tirava da lista, sem avisar, os arquivos acima do **Máximo de arquivos** e do **Tamanho máximo**. Agora um aviso diz o que ficou de fora e por quê, e os limites e os **Tipos aceitos** também valem ao arrastar e no Salvar.
- **Formulários:** um arquivo acima do limite de envio do servidor era descartado e o registro era salvo sem o anexo, sem aviso. Agora o campo avisa antes de enviar e, quando o servidor recusa o arquivo ou o envio inteiro, a tela mostra o tamanho e o limite e nada é salvo.
- **Formulários:** no campo Imagem, o **Tamanho máximo** não valia (o limite era sempre 5 MB) e escolher **Imagens (todas)** ou extensões em **Tipos aceitos** recusava qualquer arquivo. Agora os dois funcionam, a imagem recusada deixa de ser enviada no Salvar e o campo sem tipos configurados passa a aceitar WebP. O envio de imagem da Assinatura também aceita **Imagens (todas)**.
- **Formulários:** arquivo de extensão que o app não grava por segurança (como `.svg` e `.xml`) passa a ser recusado ao escolher, com aviso no campo, e não só depois do Salvar.
- **Formulários:** no campo Imagem que guarda a foto na própria coluna (sem `storage`), o **Tamanho máximo** e os **Tipos aceitos** podiam ser contornados numa requisição adulterada. Agora valem sempre no Salvar.
- **Formulários:** um registro cuja foto já gravada (campo Imagem sem `storage`) era maior que o **Tamanho máximo** do campo não podia ser salvo sem trocar a foto. Agora a foto que já estava gravada não é conferida de novo; o limite vale para a imagem nova.
- **Formulários:** com a mesma tela aberta em dois lugares, salvar um checklist gravado com `saveChecklist()` (Usuários, Grupos, Perfis, Programas e Planos do app base) desfazia a marca feita na outra aba. Agora cada Salvar grava só o que mudou naquela tela e avisa o que a outra pessoa marcou ou desmarcou.
- **Banco de dados (SQLite):** com o app em uso por mais de uma pessoa ao mesmo tempo, salvar um registro podia falhar com "database is locked". Agora a gravação espera a outra terminar. Republique o projeto para aplicar.
- **Permissões:** nas telas de Usuários, Grupos, Perfis e Programas, buscar na lista antes de salvar tirava do cadastro os itens que ficavam fora da busca; em Grupos, Perfis e Programas, as ações liberadas deles também eram apagadas. Agora só muda o que foi marcado ou desmarcado.
- **Upload:** no Upload Múltiplo (`<mad-multi-file-field>`) e na coluna Arquivos da Lista de itens (`type="files"`), salvar de novo sem recarregar a tela regravava os arquivos recém-enviados: o anexo trocava de código a cada Salvar e, com o arquivo gravado no banco (`storage="db"`), era duplicado. Agora o arquivo é enviado e gravado uma vez só.
- **Upload:** quando o Salvar falhava depois de um anexo ter sido removido, o registro continuava com o anexo, mas o arquivo já tinha saído do disco. Agora o arquivo só é apagado quando o Salvar dá certo.
- **Upload:** numa tela redesenhada inteira depois de uma ação, o Upload Múltiplo gravado em outra tabela (`mode="table"`) aparecia sem os anexos e o Salvar seguinte apagava todos. Agora os anexos continuam na tela e no registro.
- **Upload:** no Upload Múltiplo gravado na própria coluna (`<mad-multi-file-field>` sem `mode="table"`), com duas abas, o arquivo que uma anexava sumia do registro quando a outra salvava e ficava sobrando no disco; e o arquivo que uma removia voltava ao registro, já sem o arquivo no disco. Agora cada aba só mexe no que ela mesma mostrou.
- **Upload:** quando o Upload Múltiplo por vírgula abria sem os arquivos do registro, o Salvar apagava todos. Agora só é apagado o arquivo que o campo mostrou e o usuário removeu.
- **Upload:** nos campos de arquivo único gravados no disco (`<mad-file-field>`, `<mad-image-field>`, avatar, assinatura e a coluna de arquivo da Lista de itens), remover ou trocar o arquivo o apagava do disco na hora. Se o Salvar falhasse depois, o registro continuava apontando para um arquivo que não existia mais. Agora o arquivo antigo só sai do disco quando o Salvar dá certo, e o arquivo novo de um Salvar que falhou não fica sobrando.
- **Upload:** com `file-name="original"` ou `"record"`, enviar um arquivo com o mesmo nome do que já estava gravado trocava o conteúdo mesmo quando o Salvar falhava. Agora a troca só acontece quando o Salvar dá certo. Vale também para o Upload Múltiplo e para a coluna Arquivos.
- **Upload:** depois de trocar um arquivo único e salvar, cada novo Salvar na mesma tela enviava e gravava o arquivo de novo, com outro nome; no campo de arquivo, remover logo depois de salvar não removia. Agora o arquivo é gravado uma vez só e o Remover vale sem recarregar a tela.
- **Upload:** com duas abas, a que estava desatualizada devolvia ao registro o arquivo único que a outra tinha trocado ou removido, e a que tinha removido um arquivo apagava o que outra pessoa enviasse depois. Agora o arquivo que está no registro só é trocado ou removido por quem o vê, e quem salva é avisado.
- **Usuários:** salvar o cadastro de uma pessoa desligava a verificação em duas etapas que ela tivesse ligado enquanto o cadastro estava aberto. Agora o que a tela não mostra fica como está.
- **Usuários:** as telas **Minha conta**, **Usuários** e **Usuários da unidade** levavam para o navegador, junto com o cadastro, a senha embaralhada e a semente do autenticador. Agora nenhuma das duas sai do banco.
- **Usuários:** duplicar um usuário copiava a verificação em duas etapas dele, e o aplicativo de uma pessoa gerava os códigos da outra. A conta duplicada nasce sem.
- **Usuários:** com a cortina **Perfis e módulos** de um usuário aberta em dois lugares (duas pessoas ou duas abas), quem salvava por último desfazia o perfil ou o módulo que o outro tinha marcado e devolvia o que ele tinha desmarcado. Agora o Salvar inclui só o que foi marcado e remove só o que foi desmarcado naquela tela. Republique o projeto para aplicar.
- **Usuários:** quando outra pessoa marca ou desmarca um perfil ou um módulo enquanto a tela está aberta, o Salvar avisa o que ela mudou e pede para atualizar a tela.
- **Banco de dados:** numa conexão por Driver REST com a chave em somente leitura, comandos de estrutura (`TRUNCATE`, `DROP`, `CREATE`, `ALTER`) deixam de ser executados em MySQL e MariaDB — antes só os de dados eram barrados, dando a falsa impressão de que a trava funcionava. Republique o projeto para aplicar.
- **Banco de dados:** com a escrita liberada, o console do Studio volta a criar `trigger`, `procedure` e `function` com corpo `BEGIN ... END` em SQLite, MySQL e MariaDB — antes eram recusadas com "Múltiplas instruções SQL não são permitidas". Republique o projeto para aplicar.
- **Listagens:** o botão **Limpar** de uma listagem com `:filters` num `<mad-grid model="…">`, ou com o filtro fixo declarado na classe, tirava também esse filtro e mostrava todas as linhas da tabela. Agora ele limpa o que sempre limpou (filtros do usuário, busca, filtros de coluna e ordenação) e não toca no filtro fixo.
- **Listagens:** a edição na célula gravava qualquer valor: abaixo do mínimo, acima do máximo, fora das opções do select ou do combo e texto em coluna numérica. Agora o servidor confere o valor pelo que a coluna declara (`edit-min`, `edit-max`, `edit-opts`, o combo com seus filtros, data válida) e pela regra do campo em `rules()` do Model; o valor recusado não é gravado e o motivo aparece na célula e num aviso.
- **Listagens:** a edição na célula e o **Excluir** do `<mad-del>` só alcançam linhas que a listagem mostra. Antes, uma requisição adulterada editava ou excluía qualquer linha da tabela, por cima do filtro fixo.
- **Listagens:** o **Excluir** do `<mad-del>` respondia "Registro excluído com sucesso." mesmo sem excluir nada: quando a linha já tinha sido excluída por outra pessoa, quando ela não pertencia à listagem ou quando o Model recusava a exclusão. Agora o aviso diz o que aconteceu e a linha que continua no banco continua na tela.
- **Listagens:** editar na célula uma coluna declarada entre chaves (`field="{estoque}"`) dava erro. O número de linhas por página pedido pelo navegador passa a ter teto: 100, ou o `per-page` da tela quando ele é maior. E uma listagem com `require-filter` não é mais destravada por uma requisição adulterada.
- **Listagens:** numa listagem com coluna calculada (`evaluate`), as células calculadas e o total do rodapé viravam `0,00` ao trocar de página, buscar, filtrar, ordenar ou mudar o **Por página**, e só voltavam com F5. O saldo acumulado (`running`) e a coluna calculada do PDF exportado tinham o mesmo defeito. Republique o projeto para aplicar.
- **Listagens:** o rodapé de totais somava só a página exibida, enquanto a exportação trazia o total de tudo. Agora o rodapé soma o resultado inteiro da listagem (todas as linhas que passam pelos filtros, pela busca e pelo filtro fixo): é o mesmo número em qualquer página e o mesmo **TOTAL GERAL** da planilha e do PDF.
- **Listagens:** o total do rodapé não mudava depois de editar na célula, de excluir uma linha ou de salvar um registro pelo formulário em cortina lateral. Agora ele acompanha a alteração sem recarregar a tela.
- **Listagens:** coluna calculada com `sort` gerava erro de SQL ao clicar na seta do cabeçalho. Agora o clique ordena pelo valor calculado. Quando a fórmula usa coluna de outra tabela (`{categoria->comissao}`), a coluna não oferece a seta.
- **Listagens:** na coluna calculada, um valor negativo colado no operador (`{a}-{b}` com `b` negativo), em notação científica ou com zero à esquerda fazia a célula mostrar 0 ou um número errado.
- **Listagens:** uma requisição com argumento de tipo errado nas ações da listagem (por exemplo, o número da linha em forma de lista na edição na célula, ou texto no **Por página**) respondia com erro técnico. Agora responde "Requisição inválida. Atualize a tela e tente de novo.", sem alterar nada.
- **Listagens:** na edição na célula, reabrir o editor logo depois de confirmar um valor podia terminar com o valor antigo gravado por cima do novo, sem aviso. O editor reaberto mostrava o valor de antes, e a chegada da resposta do servidor gravava sozinha o que estivesse no campo (o valor antigo, ou o que estava pela metade). Agora o editor reaberto parte do valor que você confirmou, a resposta que chega não fecha nem altera o editor aberto e nada é gravado sem você confirmar.
- **Listagens:** com os campos sempre visíveis (`edit-mode="inline"`), ir de uma célula para a vizinha da mesma linha com Tab fazia o campo em que você já estava digitando perder o cursor, e gravava o texto pela metade. Agora a linha só é redesenhada quando você sai dela.
- **Listagens:** paginar, buscar, ordenar ou filtrar com o editor de uma célula aberto gravava o que estava sendo digitado, sem confirmação. Agora o editor continua aberto com o que você digitou quando a linha segue na tela; se a linha saiu da tela, a digitação é descartada e um aviso informa que ela não foi gravada.
- **Listagens:** quando o salvamento de uma célula não chegava ao servidor (queda de conexão), a célula voltava ao valor anterior sem dizer nada. Agora aparece o aviso de que a alteração não foi gravada.
- **Listagens:** o editor aberto por duplo clique ou pelo lápis já abre com o cursor no campo, em qualquer linha (antes só na primeira).
- **Listagens:** cada salvamento na célula fazia dois pedidos ao servidor (o segundo só para o rodapé de totais). Agora é um só.
- **Listagens:** a Ação Grid com **Navigate** (`<mad-act navigate="…">`) não aparecia na linha. Agora ela navega como um `<mad-nav>`, respeitando **Abrir em** (`drawer`, `row`).
- **Listagens:** o `<mad-del>` dentro de um grupo de ações (`<mad-action-group>`) não aparecia no menu, e um grupo só com ele nem era desenhado. Num `<mad-grid model="…">` (grade de página ou painel) o grupo inteiro não aparecia.
- **Listagens:** a ação configurada com confirmação em **Popover** (`confirm-popover`) executava direto, sem perguntar.
- **Listagens:** com **Ações sem permissão = Ocultos**, a ação em lote que o perfil não tem continuava na barra de seleção, cinza. Agora ela some, como os outros botões. No modo **Desabilitados** ela continua cinza e passa a mostrar a dica do motivo ao passar o mouse.
- **E-mail:** o servidor, a porta, o usuário, a senha e o remetente preenchidos em Preferências › E-mail não tinham efeito: os e-mails saíam sempre pela configuração do servidor (`.env`). Agora, com **Enviar os e-mails por este servidor** ligado, valem para todos os e-mails do app (redefinição de senha, convite, código de verificação, notificações, os enviados pelas telas e os que já estão na fila) e passam na frente do `.env`. Republique o projeto para aplicar.
- **E-mail:** o Template Global não era aplicado aos e-mails enviados pelo código das telas (`MailService::send`) nem às notificações por e-mail dos fluxos de aprovação.
- **E-mail:** com o envio desligado (`MAIL_MAILER=log`, o padrão do app publicado), redefinição de senha, convite e código de verificação deixavam de sair sem nenhum registro. Agora cada e-mail que não sai fica em `storage/logs/mail.log`, com destinatário, assunto e motivo (nunca o conteúdo), com qualquer `LOG_LEVEL`, e aparece em Preferências › E-mail. Falha de envio também fica registrada. Republique o projeto para aplicar.
- **E-mail:** o texto de apoio de **Enviar os e-mails por este servidor** dizia "Desligado…" mesmo com a opção ligada. Agora acompanha o estado.
- **E-mail:** sem modelo gravado nas Preferências, o e-mail do código de verificação saía com um texto simples, diferente do modelo que a tela mostra. Agora usa o mesmo modelo.
- **Preferências:** os campos de servidor da aba E-mail tinham os rótulos em inglês em todos os idiomas.
- **Preferências:** os campos de senha e de chave (servidor de e-mail, IA e reCAPTCHA) ganharam o botão **Apagar**, com confirmação, para remover o valor gravado.
- **Preferências:** com a chave do app (`APP_KEY`) trocada, a senha e as chaves gravadas aparecem como não configuradas, com o aviso para informar de novo, e o app deixa de enviar o valor ilegível ao servidor de e-mail, ao provedor de IA e ao provedor de cobrança.
- **Preferências:** com a tela **Preferências** aberta em dois lugares (duas pessoas ou duas abas), quem salvava por último desfazia o que o outro tinha gravado, em qualquer aba. O mesmo acontecia com o que outra tela gravava nesse intervalo, como a configuração de cobrança e as colunas de listagem de cada usuário. Agora o Salvar grava só o que foi alterado naquela tela. Republique o projeto para aplicar.
- **Preferências:** quando outra pessoa altera uma preferência enquanto a tela está aberta, o Salvar avisa quais campos mudaram e pede para atualizar a tela. Se as duas alteraram o mesmo campo, vale o último Salvar, e o aviso diz isso.
- **Preferências:** salvar as Preferências sem mexer nos **Termos de uso** podia zerar o aceite de todos os usuários. Agora o aceite só é zerado quando o texto dos termos muda.
- **Cadastro e redefinição de senha:** uma falha do banco nessas telas públicas não revela mais se o e-mail informado já tem conta.
- **Editor HTML:** cor do texto, cor de fundo, alinhamento, sublinhado, tamanho de fonte, recuo e a borda da tabela eram perdidos ao salvar. Agora a formatação do editor é gravada inteira.
- **Cobrança:** salvar as Preferências com a tela de Cobrança sendo alterada em outra aba desfazia as credenciais do provedor recém-gravadas.
- **Cobrança:** a tela **Cobrança › Configurações** tinha o mesmo defeito: o provedor, a régua de inadimplência e os meios de pagamento voltavam ao valor de quando a tela abriu. Passa a seguir a mesma regra.
- **Login:** a senha digitada ficava guardada na tela entre um passo e outro (unidade, termos, código de verificação, troca de senha) e ia e voltava do navegador a cada passo. Agora é conferida uma vez e não fica guardada.
- **Login:** depois de uma conta conferir o código de verificação e parar na troca de senha, dava para entrar com outra conta, na mesma tela, sem o código dela. Agora cada usuário e senha digitados começam do zero.
- **Login:** conta com a verificação por aplicativo ligada e sem semente gravada entrava com um código que não dependia de segredo nenhum. Agora o login é recusado.
- **Login:** a troca de senha obrigatória podia ser feita sem o código da verificação em duas etapas. Agora só depois dele.
- **Minha conta:** o QR code e a chave manual do autenticador aparecem só durante a ativação e não são mostrados de novo depois de confirmada, cancelada ou com a tela reaberta. Desativar a verificação em duas etapas passa a apagar a semente.
- **Lista de itens e Detail Form:** na tela que grava as linhas pelo código (`loadDetailRows()` + `saveDetailItems()`), salvar devolvia as colunas que a lista não mostra ao valor de quando a tela abriu, desfazendo o que outra tela tivesse gravado. Agora são gravadas as colunas da lista e só o que o código da tela mudou.
- **Lista de itens e Detail Form:** num app com Multi-unidade, a linha aceitava, numa requisição alterada, o cliente ou o usuário de outra unidade, mesmo sem ele aparecer no combo da linha. Agora o Salvar é recusado e a mensagem diz a lista, a linha e o campo.
- **Lista de itens e Detail Form:** quando um botão ou o gancho de carga da tela mudava uma coluna que a lista não mostra, o Salvar regravava também as outras colunas ocultas da linha com o valor de quando a tela abriu, desfazendo o que outra pessoa tinha gravado. Agora só a coluna alterada é gravada.
- **Checklist:** `saveChecklist()` apagava e recriava todas as marcas a cada Salvar, e as colunas da ligação que o checklist não mostra voltavam ao padrão. Agora só a marca que mudou é incluída ou removida.
- **Checklist:** com `saveChecklist()`, salvar desmarcava os itens que quem salva não enxerga (de outra unidade), porque eles não aparecem no checklist dessa pessoa. Agora continuam marcados.
- **Lista de itens:** trocar o valor de uma coluna com **Ao alterar** (`on-change`) duas vezes seguidas, ou alterar valores rapidamente numa lista com `on-totalize`, podia deixar na linha o resultado da PRIMEIRA escolha (por exemplo, o preço do produto anterior) e o total desatualizado, e era isso que o Salvar gravava. Os pedidos da lista agora saem um de cada vez, na ordem em que você fez as mudanças. Vale também para a coluna que depende de outra (`depends-on`): limpar ou trocar o campo pai com as opções a caminho não enche mais o dependente com as opções do valor anterior.
- **Checklist e seleção múltipla em outra tabela:** uma marca de item de outra unidade, enviada numa requisição alterada, virava ligação. Agora o Salvar é recusado, com a mensagem no campo.
- **API REST:** nas linhas de detalhe, a conferência da chave de outro cadastro podia ser contornada informando a chave de uma linha de outro registro.
- **Verificação em duas etapas:** quem tinha a senha de uma conta podia tentar códigos de verificação sem limite: bastava recomeçar o login a cada erro. Agora, no 5º código errado vindo do mesmo endereço, a verificação daquela conta fica bloqueada por 15 minutos para aquele endereço, e informar a senha certa de novo não libera antes do prazo. Vale para o código do aplicativo e para o código por e-mail. Republique o projeto para aplicar.
- **Verificação em duas etapas:** quando os códigos errados vêm de vários endereços, a conta inteira fica bloqueada pelo mesmo prazo ao somar 15. Quem erra de um lugar só não impede o dono da conta de entrar de outro.
- **Verificação em duas etapas:** a conta bloqueada não recebe novo código por e-mail, e cada bloqueio fica registrado no log do app (quem, de onde e por quanto tempo, sem o código digitado).
- **Licenciamento:** os **Módulos avulsos** da licença, nas telas da unidade e da empresa, tinham o mesmo defeito e passam a seguir a mesma regra. O módulo avulso que continua marcado não é mais regravado a cada Salvar.
- **Calendário:** arrastar ou redimensionar só vale para evento que a agenda mostra a quem está logado. Antes, uma requisição adulterada movia um evento escondido pelo `<mad-calendar-filter>`, pelo critério do `onSearch()`, pelo filtro por unidade ou pelos recortes do Model.
- **Calendário:** a agenda de um usuário podia sair com o filtro avaliado para outro (por exemplo `<mad-calendar-filter :value="session('userid')">`) quando a sessão dele ainda não tinha desenhado a agenda. Agora cada sessão usa só a configuração que ela mesma desenhou.
- **Calendário:** quando o arrastar é recusado, a agenda é redesenhada e o evento volta ao lugar. Os avisos "Operação não autorizada." e o de arrastar desabilitado saem acentuados e traduzidos.
- **Gantt:** em tela escrita como subclasse de `MadGanttComponent`, o arrastar padrão só reagenda tarefa que a consulta da tela (`buildQuery()`) devolve. Antes, uma requisição adulterada reagendava qualquer tarefa da tabela.
- **Usuários da unidade:** o Salvar do administrador da unidade trocava os perfis e os módulos da pessoa pelo que a tela mostrava, removendo o perfil que o dono do sistema tinha dado com ela aberta. Agora grava só o que foi marcado ou desmarcado naquela tela e avisa o que a outra pessoa mudou.

### Atenção

- **Reatividade (MadWire):** um campo ligado por `mad:model` a `recordId`, `registroId` ou `editingId` deixa de receber o valor do navegador. Esses nomes guardam o registro aberto e só o código da tela os altera.
- **Usuários:** com Multi-unidade, empresas ou Licenciamento ligados, a tabela de usuários (`mad_iam_user`) segue a regra dos demais cadastros: cada pessoa vê os usuários da unidade em que está (no modo "todas as unidades", os de todas as suas). Para alguém aparecer em outra unidade, vincule-o a ela em Usuários, aba Unidades. Quem tem a tela Usuários continua vendo todos, e projeto sem separação por unidade ou empresa não muda.
- **Código:** `User::find()` e `User::query()` deixam de achar usuário de outra unidade fora das telas de administração. Onde isso é necessário (avisar um gestor de outra unidade, por exemplo), use `User::allUnits()->find($id)`; num componente, passe `:query` com `User::allUnits()`.
- **Código:** os eventos `deleting` e `deleted` do Model da linha filha passam a rodar quando a linha é removida pela tela, também em `saveDetailItems()`. Um `deleting` que devolve `false` mantém a linha.
- **Código:** uma linha enviada com a chave de um registro que não existe mais deixa de ser recriada como linha nova. Para incluir uma linha, envie-a sem a chave.
- **Código:** os eventos `deleting` e `deleted` do Model da tabela de ligação passam a rodar quando uma opção é desmarcada, e um `deleting` que devolve `false` mantém a ligação. Na coluna Arquivos da Lista de itens, o Model que recusa a exclusão passa a manter também o arquivo no disco.
- **Código:** no Upload Múltiplo por vírgula, o caminho enviado como "mantido" que não está na coluna do registro deixa de ser gravado nela.
- **Código:** a coluna que a tela não tem só é gravada quando o código a atribui: `$this->form->set('coluna', $valor)`, os extras de `fill()` e de `save()`, ou a atribuição no próprio registro. Para voltar a gravar tudo o que a tela carregou, chame `$this->form->rewriteLoaded()` antes do `save()`, ou `rewriteLoaded('status', 'saldo')` para colunas específicas.
- **Código:** atribuir no registro uma coluna que a tela não tem, antes de `$this->form->save($registro)`, passa a valer. Antes o formulário gravava por cima o valor de quando a tela abriu.
- **Código:** a regra vale para `form->save()` e `form->fillRecord()` quando o formulário foi carregado com o registro (`fill($registro)`). Quem carrega com `fill($registro->toArray())` ou grava com `$registro->fill((array) $this->form->getData())` continua gravando todas as colunas. `getData()` não mudou: continua devolvendo todas as colunas do registro.
- **Código:** numa linha de Lista de itens ou Detail Form, o valor que o código põe numa coluna fora da grade (`setRows()`, gancho `onSaveDetail`) continua sendo gravado; o valor que só voltou do navegador igual ao que estava no banco não é mais.
- **Código:** campo criado só no navegador (por JavaScript) ou valor enviado por `MadWire.call(el, 'acao', [], {campo: valor})` ou `MadWire.set()` precisa ser declarado no `mount()` da tela: `$this->form->accept('campo')`. Sem isso o valor é ignorado, e o log do app diz qual campo foi recusado e em qual tela. Campos `<mad-*>`, campo HTML escrito no Blade com `mad:model` e campos de `MadMessage::confirm(...)->field(...)` não precisam de nada.
- **Código:** numa Lista de itens ou Detail Form, valor posto por JavaScript numa coluna que a lista não tem deixa de ser gravado. Inclua a coluna na lista (`<mad-field-list-column field="coluna" type="hidden" />`) ou preencha pelo servidor (`setRows()`, gancho `onSaveDetail`).
- **Código:** `created_at`, `updated_at`, `deleted_at`, as colunas de quem criou, alterou ou excluiu, a chave gerada pelo banco e `tenant_id` (em tabela isolada por empresa) não são mais gravados a partir de um campo da tela. Para gravá-los, atribua no registro ou use `$this->form->save($registro, ['coluna' => $valor])`.
- **Código:** `setProp()` e `clearFilter()` só escrevem em filtro da tela (propriedade pública `string` ou `array` declarada na tela, fora de `$notFilterProps`) e no período (`mes`, `ano`, `dtIni`, `dtFim`, `preset`). Chamada com outra propriedade, como `setProp('aba', 'x')` ou `setProp('search', 'x')`, deixa de ter efeito e o log do app diz qual propriedade foi recusada e em qual tela. Para mudar pelo navegador uma propriedade que não é filtro, ligue o campo com `mad:model` ou crie uma ação na tela.
- **Código:** numa listagem que chama `parent::mount()`, a query string da abertura só preenche os filtros da tela e o período. `?search=…`, `?sortBy=…` e os outros nomes da própria grade deixam de ser lidos da URL. `currentFilters()`, `drillTo()`, `filterStateSnapshot()` e `totalActiveFilters()` de uma listagem também passam a considerar só os filtros da tela e o período.
- **Código:** na edição na célula, a regra do campo em `rules()` do Model passa a valer (antes só valia no formulário). Uma coluna obrigatória não pode mais ser esvaziada pela célula, e um select editável só aceita as chaves de `edit-opts`.
- **Código:** `MadUserError::message($e)` passa a tratar como erro técnico também a exceção de biblioteca (envio de e-mail, chamada HTTP, Eloquent): a tela mostra o aviso genérico em vez do texto da biblioteca. Para mostrar um texto seu, capture a exceção e lance uma `\RuntimeException` com a frase para o usuário.
- **Código:** `$this->form->get()`, `getData()` e as linhas de um Detail Form passam a devolver o conteúdo de um Editor HTML já limpo. Mídia embutida (`<iframe>`, vídeo), `<style>`, formulários e atributos fora da lista de `docs/editor-html.md` são removidos, também nos modelos de e-mail e nos termos de uso editados em Preferências.
- **Código:** a listagem que sobrescreve `query()`, `_buildQuery()`, `_runQuery()`, `_autoQuery()`, `loadData()`, `_normalizeRows()`, `_applyEvaluates()` ou `_computeTotals()` continua com o total calculado sobre as linhas carregadas (a página), agora com o aviso **nesta página** quando há mais linhas do que a página mostra. `_computeTotals()` continua somando as linhas carregadas; o rodapé da tela passa por `_footerTotals()`.
- **Código:** coluna calculada com `sort` cujo `field` também é uma coluna do banco passa a ordenar pela fórmula, não mais pela coluna.
- **Código:** regra `exists` escrita à mão sobre um cadastro separado por unidade, empresa ou usuário passa a recusar ("selecionado é inválido") o valor que o registro já tinha e que quem edita não enxerga: antes o campo o enviava vazio. Faça a regra valer só quando o valor muda, com `Rule::when(...)`; exemplo em `docs/multi-tenancy.md`. Models gerados pelo MadBuilder já saem assim ao republicar.
- **Código:** tela própria que mostra várias linhas de uma tabela chave-valor num formulário só pode usar o mesmo recurso: `$this->form->valuesLoaded('fonte', $valores)` no `mount()` e `$this->form->valuesChanged('fonte', $valores)` no Salvar, que devolve as chaves alteradas naquela tela.
- **Código:** quem lê `two_factor_secret` do usuário recebe o valor cifrado: use `$user->twoFactorSecret()` para ler e `$user->setTwoFactorSecret()` para gravar. `$user->toArray()` e o JSON do usuário deixam de trazer `password` e `two_factor_secret`.
- **Código:** `LoginForm::$pendingPassword` deixou de existir, e o código de verificação por e-mail não fica mais em `session('2fa_email_code')['code']`.
- **Código:** `MadWire.call()` passa a devolver o desfecho da chamada (`{ ok: true }` ou `{ ok: false, reason: 'network' | 'server' | 'error' | 'client' }`); antes devolvia `undefined`. Existe também `MadWire.enqueue(componente, fn)` para quem fala com o servidor por conta própria e precisa respeitar a ordem das chamadas do componente.
- **Código:** em calendário com `beforeEventUpdate()` ou `afterEventUpdate()` próprios, os dois deixam de ser chamados quando o evento não é da agenda: a recusa acontece antes. O evento que ficou de fora só pelo filtro que o usuário escolheu na tela, ou pela semana visível, continua podendo ser movido.
- **Código:** o arrastar do calendário e o do Gantt passam a buscar o registro pela coluna do `id-field` (antes, sempre pela chave primária do Model) e, para um registro que não está na tela, respondem com um aviso em vez do diálogo de erro.
- **Código:** o endereço de eventos (`getEvents`) chamado numa sessão que nunca desenhou a agenda devolve lista vazia. Antes ele aproveitava a configuração desenhada por outra sessão. Quem consome esse endereço de fora da tela precisa abrir a agenda na mesma sessão antes.
- **Formulários:** em tabela filha com exclusão lógica (`deleted_at`), a linha removida da lista deixa de ser apagada de vez e fica marcada como excluída, como o registro principal. Se a tabela tem índice único (pedido + produto, por exemplo), a linha excluída continua ocupando o índice, e incluir o mesmo item de novo acusa registro duplicado.
- **Formulários:** em tabela de ligação com exclusão lógica (`deleted_at`), a opção desmarcada deixa de ser apagada de vez e fica marcada como excluída. Marcar a mesma opção de novo devolve a mesma linha, com as colunas extras que ela tinha.
- **Formulários:** a ligação com um item que a lista de opções não mostra (lista filtrada) deixa de ser removida ao salvar. Para removê-la pela tela, o item precisa aparecer na lista.
- **Formulários:** nos campos gravados por vírgula, o item que a lista de opções não mostra deixa de sair da coluna ao salvar. Para tirá-lo pela tela, ele precisa aparecer na lista. A opção marcada agora entra no fim da coluna, e as que já estavam ficam na ordem em que estavam.
- **Formulários:** salvar uma tela aberta há mais tempo passa a mostrar o aviso "Itens já removidos" quando outra pessoa já desmarcou uma opção, removeu um anexo ou trocou um arquivo que essa tela ainda mostrava. O restante é salvo; atualize a tela para ver a situação atual.
- **Formulários:** campo que não é coluna, em que o usuário digita e que o código da tela não lê, não valida nem usa numa condição `visible-when`, passa a mostrar o aviso "Valor não gravado" depois de salvar. Se o campo é só de tela (lido por JavaScript, por exemplo), declare-o com `screen-only` na tag ou `screenOnly()` no `mount()`.
- **Formulários:** tela gerada com um campo que ficou com o nome antigo de uma coluna renomeada deixa de salvar enquanto esse campo tiver valor. Abra a tela no editor e escolha a coluna do campo.
- **Formulários:** campos de Upload, Upload Múltiplo e Imagem que já tinham **Tamanho máximo**, **Máximo de arquivos** ou **Tipos aceitos** preenchidos passam a recusar o que está fora dessas regras. Confira os valores no painel; campo sem nada configurado continua como estava.
- **Formulários:** no campo Imagem, a foto da câmera e a imagem recortada ou girada saem em um tipo que o campo aceita e dentro do **Tamanho máximo** (a qualidade é reduzida para caber). O **Tamanho máximo** aceita decimais (`2,5MB`).
- **Formulários:** nos campos de seleção múltipla sobre uma tabela (`model=`) gravados por vírgula, o Salvar passa a recusar a marca de um item que a pessoa logada não enxerga mais (mudou de unidade ou foi excluído com a tela aberta), com o aviso "Um dos itens marcados não pode ser gravado" no campo. Campo com `:query` própria ou com opções fixas continua como estava. Se a tela precisa gravar um item fora da lista, atribua o valor no código do Salvar (`$form->set()` ou os extras do `save()`).
- **Banco de dados:** numa conexão por Driver REST em somente leitura, `SET`, `USE` e qualquer comando que não seja uma consulta passam a ser recusados (antes alguns executavam). Use uma conexão com escrita liberada para esses casos.
- **E-mail:** os campos de servidor de Preferências › E-mail só valem com **Enviar os e-mails por este servidor** ligado. App que já tinha esses campos preenchidos continua enviando como antes (pelo `.env`) até alguém ligar a opção. Antes de ligar, confira com **Enviar e-mail de teste**.
- **E-mail:** com um Template Global gravado, os e-mails enviados por código com `MailService::send` passam a sair com a moldura. Para enviar sem ela, passe `false` no sexto argumento: `MailService::send($para, $assunto, $html, [], [], false)`. Um corpo que já é um documento HTML completo não recebe a moldura.
- **E-mail:** as Preferências › E-mail valem para o app inteiro: todas as empresas e unidades enviam pelo mesmo servidor e com o mesmo remetente.
- **E-mail:** a senha do servidor de e-mail depende da `APP_KEY` do app. Se a chave for trocada, digite a senha de novo em Preferências › E-mail.
- **E-mail:** os e-mails na fila passam a depender da `APP_KEY`: os que estiverem aguardando no momento de uma troca de chave não são enviados e vão para a lista de falhas.
- **Conteúdo antigo:** o que foi gravado antes desta versão continua no banco como entrou. Se o seu app escreve esse conteúdo num Blade próprio, num documento ou num e-mail com `{!! !!}`, rode `php artisan mad:html-clean <Model> <coluna> --dry-run` para ver o que seria limpo e depois sem `--dry-run`, ou exiba com `{!! \Mad\Util\MadHtmlSanitizer::sanitize($valor) !!}`.
- **Listagem:** a coluna `html` mostra o valor do banco limpo. HTML com script, evento, `<svg>` ou atributo `data-*` vindo do banco deixa de aparecer; para HTML montado pelo código, use um transformador na coluna.
- **Preferências:** código que lê uma preferência de segredo direto do banco ou por `SystemPreferenceService::getPreferences()` (`smtp_pass`, `ai_openrouter_api_key`, `ai_anthropic_api_key`, `google_recaptcha_secret_key`) passa a receber o valor cifrado. Leia com `SystemPreferenceService::secret('ai_openrouter_api_key')`. `BRecaptcha::verify()` continua aceitando a chave como está gravada.
- **Preferências:** esvaziar o campo **Secret Key** do reCAPTCHA e salvar não apaga mais a chave: em branco mantém. Para apagar, use o botão **Apagar** do campo.
- **Preferências:** os segredos dependem da `APP_KEY` do app. Se ela for trocada, informe-os de novo, ou mantenha a chave anterior em `APP_PREVIOUS_KEYS`. Enquanto a chave secreta do reCAPTCHA estiver ilegível, o reCAPTCHA não é exigido no login.
- **Preferências:** o Salvar não grava mais os valores padrão que a tela mostra quando não há nada gravado (modelos de e-mail, dias de retenção do GED, validade da senha): a preferência só passa a existir no banco quando o valor é alterado. O app usa o mesmo padrão nos dois casos.
- **Listagens:** o número do rodapé de totais muda nas listagens com mais de uma página: passa a ser o total de tudo, não o da página. A coluna que o banco não consegue somar (coluna de outra tabela como `{categoria->comissao}`, fórmula que usa uma delas, texto, saldo acumulado, atributo calculado no Model) continua somando a página e o rodapé dela diz **nesta página**. O relatório (`per-page="0"`) não muda.
- **Listagens:** confirmar o editor da célula sem alterar o valor (Enter, clicar fora, passar com Tab) não chama mais `onInlineSave`. Quem sobrescreveu `onInlineSave` contando com a chamada em todo blur deixa de recebê-la quando o valor não mudou.
- **Listagens:** telas que tinham um `<mad-act navigate="…">` ou um `<mad-del>` dentro de grupo passam a mostrar esses botões, que antes não apareciam. Numa listagem `<mad-grid self>` o `<mad-del>` não tem método embutido (ele é do `<mad-grid model="…">`): use `<mad-act method="onDelete" … danger />`.
- **Listagens:** quem monta a ação em código com `GridAction::make(...)->params([...])` passa a ter `{campo}` trocado pelo valor da linha nos textos dos parâmetros (antes iam como escritos). Campo que a linha não tem fica como foi escrito.
- **Listagens:** o botão de ação em lote recusado pelo perfil sai com `aria-disabled="true"` e `data-mad-deny` no lugar de `disabled` (igual aos botões de linha). CSS ou teste do app que procurava `:disabled` nesse botão precisa procurar `[data-mad-deny]`.
- **Login:** depois da senha, a pessoa tem 10 minutos para concluir os passos seguintes. Passado o prazo, com a página recarregada ou depois de 5 códigos errados, o app pede usuário e senha de novo. Quem estiver no meio de um login no momento da atualização também. Quem já está logado não é afetado.
- **Segurança:** a semente do autenticador depende da `APP_KEY` do app. Se a chave for trocada sem a anterior em `APP_PREVIOUS_KEYS`, quem usa aplicativo autenticador não consegue entrar: o login avisa que o código não pôde ser conferido, e a verificação em duas etapas da conta precisa ser desligada no banco para a pessoa ativar de novo.
- **Banco:** semente com 32 caracteres ou mais (o app gera 16) continua em texto puro, porque cifrada não cabe na coluna.
- **Lista de itens:** em `saveDetailItems()`, de cada linha carregada com `loadDetailRows()` são gravadas as colunas da lista e, das que ela não mostra, só as que o código mudou: a chave cujo valor na linha é outro que não o de quando a tela abriu, e o que o `$each` atribui. As demais ficam como estão no banco, mesmo quando o código muda outra coluna da mesma linha.
- **Lista de itens:** linha nova, ou carregada sem `loadDetailRows()`, continua sendo gravada com todas as chaves que a linha traz. Para limitar as colunas nesse caso, use `only: [...]`.
- **Lista de itens:** os eventos `on-change`, `on-add`, `on-remove` e `on-totalize` saem em fila com as demais chamadas da tela: cada um espera o anterior responder e já recebe o estado que ele deixou. Com o Salvar em andamento, o evento espera o Salvar terminar.
- **Checklist:** com `saveChecklist()`, a ligação que continua marcada mantém a chave e as outras colunas. Quem contava com a recriação para zerá-las deve atribuir o valor no `$each`.
- **Lista de itens, Detail Form e Checklist:** as regras `exists` do `rules()` do Model da linha (ou da tabela de ligação) passam a valer também nas linhas e nas marcas, como já valiam no formulário principal. Se a tela oferece de propósito opções que essa regra não aceita (uma `:query` sem o filtro de unidade, por exemplo), ajuste a regra do Model. As demais regras (`required`, `unique`) continuam valendo só no formulário principal.
- **Verificação em duas etapas:** o usuário que errar o código 5 vezes seguidas passa a esperar até 15 minutos, mesmo com a senha correta; a mensagem na tela diz quanto falta. O bloqueio acaba sozinho. Para liberar uma conta antes do prazo, pelo código do app: `\App\Service\Iam\SecondFactorLockService::release($idDoUsuario, $endereco)`.

## [5.126.0] — 2026-10-07

### Novidades

- **Formulários:** o campo ganhou `autofocus`: o cursor começa nele quando a tela abre, sem escrever código (`<mad-input-field name="nome" autofocus />`). Vale um campo por tela, e um `$this->form->focus()` no código tem prioridade. Republique o projeto para usar.

## [5.125.0] — 2026-10-07

### Novidades

- **Formulários:** escolha em qual campo o cursor começa ao abrir a tela com `$this->form->focus('nome')` no código — um campo ao criar e outro ao editar, inclusive em cortina lateral e janela. Republique o projeto para usar.

## [5.124.0] — 2026-10-07

### Novidades

- **App gerado:** o app passa a usar o fuso horário escolhido nas Configurações do projeto, tanto nas datas e horas que grava e mostra quanto no banco de dados (PostgreSQL e MySQL). Sem fuso configurado, continua em UTC. Republique o projeto para aplicar.

## [5.123.0] — 2026-10-06

### Novidades

- **Formulários:** cada botão da barra de ações pode ficar à esquerda, no centro ou à direita, com `position="left"`, `"center"` ou `"right"` no `<mad-btn>`, no `<mad-btn-group>` e no `<mad-dropdown>`. Botão sem posição segue o alinhamento da barra. Republique o projeto para aplicar.

## [5.122.1] — 2026-10-06

### Correções

- **Segurança:** o cadastro público e o cadastro de usuários da unidade permitiam usar o login de outro usuário, inclusive o do administrador. Agora o login é único, sem diferenciar maiúsculas, e `admin` não pode ser escolhido no cadastro público. Republique o projeto para aplicar.
- **Segurança:** com o Licenciamento ligado, o administrador de uma unidade não vê nem altera mais a conta do administrador do sistema, e nome, e-mail, login e senha de quem também trabalha em outras unidades só o administrador do sistema altera. Republique o projeto para aplicar.
- **Segurança:** a Central de Comando, o acesso a todos os documentos e a entrada durante a manutenção passam a exigir o usuário `admin` do grupo Administrador, e não só o login. Republique o projeto para aplicar.

### Atenção

- **Usuários:** se o app já tiver usuários com o mesmo login, ao atualizar o mais antigo é mantido e os demais são renomeados (`login__dup2`, `__dup3`…) e desativados, com aviso no log. Confira a tela de usuários e reative quem for legítimo com outro login; os tokens MCP desses usuários precisam ser gerados de novo.

## [5.122.0] — 2026-10-06

### Novidades

- **Multi-empresa:** o administrador do app (usuário `admin` do grupo Administrador) pode passar a ver nas listagens, campos de seleção, filtros e painéis os registros de todas as empresas e unidades, sem trocar de empresa no topo. A opção vem desligada, não vale para o administrador de uma unidade nem com o Licenciamento ligado, e cadastro novo continua indo para a empresa ativa. Republique o projeto para aplicar.

## [5.121.0] — 2026-10-06

### Novidades

- **Temas:** o ícone do cabeçalho da página (e o divisor ao lado dele) pode ser ocultado em todas as telas pelo tema, na opção "Mostrar ícone do cabeçalho da página". Sem mexer nela, nada muda. Republique o projeto para aplicar.

## [5.120.0] — 2026-10-06

### Novidades

- **Listagem (grid):** o botão Exportar pode mostrar um texto ao lado do ícone com `export-label="Exportar"`; `export-label` sem valor usa "Exportar" no idioma do app. Sem a opção, continua só o ícone. Republique o projeto para aplicar.

## [5.119.3] — 2026-10-06

### Correções

- **Central de Comando:** o cartão "Debug Console" dizia "Debug ON" ao clicar em Alternar, mas nenhum painel de depuração aparecia nas telas: esse console foi descontinuado na versão 5. O cartão foi retirado para não confundir. Republique o projeto para aplicar.

## [5.119.2] — 2026-10-06

### Correções

- **Listagem (grid):** sair de um formulário aberto em página inteira pelo voltar do navegador levava a listagem de volta para a primeira página, e voltar de novo reabria a mesma listagem em vez de sair dela. Agora ela volta na mesma página, com a mesma ordem, busca e filtros; abrir pelo menu continua começando do zero. Um botão Voltar faz o mesmo com `:params="['_mad_return' => '']"`. Republique o projeto para aplicar.
- **Listagem (grid):** em listagens escritas à mão no código, salvar um formulário de página inteira (ou voltar dele) reabria a listagem na primeira página, e não na página em que o usuário estava. Agora volta na mesma página, como nas listagens geradas. Republique o projeto para aplicar.

## [5.119.1] — 2026-10-06

### Correções

- **Formulário:** um campo de lista vinda do banco (DB Combo, DB Radio…) que não conseguia carregar as opções aparecia vazio ou com as opções em branco, sem pista. Agora o motivo vai para o log do app e, com a depuração ligada, aparece no próprio campo. Republique o projeto para aplicar.

## [5.119.0] — 2026-10-06

### Novidades

- **Layout:** `<mad-drawer>` e `<mad-modal>` ganham `close-on-backdrop`: com `close-on-backdrop="false"`, o clique na área escurecida não fecha a janela, e o X e o Esc continuam funcionando.
- **Layout:** uma tela só de consulta aberta em cortina lateral ou janela pode voltar a fechar no clique fora com `protected static bool $closeOnBackdrop = true` na classe da tela.

### Correções

- **Formulário:** o formulário aberto em cortina lateral ou em janela fechava com qualquer clique fora dele e perdia o que foi digitado. Agora fecha só em Salvar, Voltar, no X ou no Esc. Republique o projeto para aplicar.
- **Detalhe:** o formulário do item do mestre-detalhe, aberto em cortina lateral ou em janela, também não fecha mais ao clicar fora. Republique o projeto para aplicar.
- **Layout:** selecionar o texto de um campo arrastando o mouse até fora da janela não a fecha mais, e `dismissible="false"` escrito sem `:` passa a ser respeitado. Republique o projeto para aplicar.

## [5.118.0] — 2026-10-05

### Novidades

- **Campos:** o `<mad-btn>` passa a aceitar `style` e outros atributos escritos na tag (`data-*`, `aria-*`, `x-*`, `onclick`, `tabindex`…). Antes eram ignorados sem aviso. Republique o projeto para aplicar.

### Correções

- **Detalhe:** na grade de itens editável, o combo que depende de outra coluna da linha ficava vazio e os totais ligados à grade não atualizavam. Republique o projeto para aplicar.
- **Detalhe:** com validação ao adicionar ou excluir item, o item não entrava nem saía da lista, sem aviso, quando ela ficava numa cortina lateral ou a validação mudava outros dados da tela. Republique o projeto para aplicar.
- **Detalhe:** comentários e blocos `@php` nos campos do item apareciam como "MAD__BLADE_…" no formulário do detalhe, e o código dos blocos não rodava. Republique o projeto para aplicar.
- **Detalhe:** valor que a tela preenchia numa linha da grade (ex.: o total copiado para a linha) aparecia zerado, e o que se digitava ali podia se perder ao salvar. Republique o projeto para aplicar.
- **Formulário:** quando uma ação ou a abertura de um formulário em janela falhava no servidor, a tela ficava esperando sem mostrar nada. Agora o erro aparece num aviso. Republique o projeto para aplicar.

## [5.117.11] — 2026-10-05

### Correções

- **Monitoramento (MadTrace):** a verificação automática de saúde do servidor era registrada como acesso no painel de Desempenho e esgotava a cota mensal de eventos em poucos dias, deixando de registrar os erros reais do app. Agora ela fica de fora. Republique o projeto para aplicar.

## [5.117.10] — 2026-10-05

### Correções

- **Calendário:** um calendário configurado só com o campo de data de início (sem data de fim) abria vazio, sem nenhum evento. Agora mostra os eventos no dia e na hora de início. Republique o projeto para aplicar.

## [5.117.9] — 2026-10-04

### Correções

- **Listagens:** no filtro avançado, o menu "Meus filtros" abria para cima e cobria o texto do painel. Agora abre para baixo quando há espaço na tela. Republique o projeto para aplicar.

## [5.117.8] — 2026-10-04

### Correções

- **Listagens:** o painel "Escolher colunas" cobria o próprio botão que o abriu e não fechava clicando nele de novo. Agora abre logo abaixo do botão, fecha pelo mesmo botão e tem um "×" para fechar. Republique o projeto para aplicar.

## [5.117.7] — 2026-10-04

### Correções

- **App gerado:** trocar de página ou baixar um arquivo enchia o console do navegador com "fragmento falhou … Failed to fetch" (notificações, mensagens e busca do cabeçalho), sem nada de errado de fato. Esses avisos falsos saíram; erros reais continuam aparecendo. Republique o projeto para aplicar.

## [5.117.6] — 2026-10-04

### Correções

- **Documentos (PDF):** num parágrafo do documento, um código como "OS-2026-0001" no fim da linha era quebrado no hífen ("OS-" numa linha e "2026-0001" na outra). Agora o código fica inteiro e a linha quebra antes dele. Republique o projeto para aplicar.

## [5.117.5] — 2026-10-04

### Correções

- **Componentes:** a linha do tempo (`<mad-timeline>`) passa a usar cores em hexadecimal (ex.: a cor cadastrada no tipo de atividade) no marcador de cada item. Antes só as cores prontas (sucesso, informação, aviso, perigo) funcionavam e as demais saíam cinza. Republique o projeto para aplicar.

## [5.117.4] — 2026-10-04

### Correções

- **Kanban:** o ícone do rodapé de texto do cartão (ex.: vendedor) aparecia grande, bem maior que o texto. Agora tem o mesmo tamanho dos outros ícones do cartão. Republique o projeto para aplicar.

## [5.117.3] — 2026-10-04

### Correções

- **Formulários:** o seletor de ícones mostrava "1739 icone s" no rodapé e outros textos sem acento. Agora mostra "1739 ícones", com todos os textos corretos e no idioma do usuário. Republique o projeto para aplicar.

## [5.117.2] — 2026-10-04

### Correções

- **App gerado:** a tela inicial do administrador ("Bem-vindo, …") mostrava textos sem acento, como "ALTERACOES HOJE", "NOTIFICACOES" e "Atalhos rapidos". Agora aparecem corretos e acompanham o idioma do usuário (português, português de Portugal, inglês e espanhol). Republique o projeto para aplicar.

## [5.117.1] — 2026-10-04

### Correções

- **Importação de dados:** ao importar uma planilha, a tela mostrava "Erro na importação" mesmo com os dados já gravados, e quem tentava de novo duplicava os registros. Agora mostra o resultado da importação, e uma falha ao registrar o histórico não vira mais erro na tela. Republique o projeto para aplicar.

## [5.117.0] — 2026-10-04

### Melhorias

- **Agente de IA:** o Chat IA do app passa a responder com o nome das pessoas em perguntas sobre vendedor, responsável ou quem criou o registro ("Ana Ribeiro", não "vendedor 3"). Ele vê só o nome dos usuários da unidade ou empresa atual; login, e-mail e senha continuam fora do alcance. Republique o projeto para aplicar.

## [5.116.5] — 2026-10-04

### Correções

- **Dashboards:** dashboard, Kanban ou calendário sem nenhum filtro declarado não mostram mais uma barra de filtros vazia, só com os botões Atualizar e Limpar. Republique o projeto para aplicar.

## [5.116.4] — 2026-10-04

### Correções

- **Formulários:** ao preencher o CNPJ com busca automática de endereço, o campo Cidade recarregava duas vezes e passava um instante em "Selecione..." antes de voltar com a cidade. Agora carrega uma vez só. Republique o projeto para aplicar.

## [5.116.3] — 2026-10-04

### Correções

- **Permissões:** com a ação Exportar negada no perfil, o botão de exportar da listagem passa a ficar apagado, com a dica "Sem permissão para exportar", igual às outras ações negadas. Antes ele parecia ativo e só os formatos dentro do menu ficavam desabilitados. Republique o projeto para aplicar.

## [5.116.2] — 2026-10-04

### Correções

- **Permissões:** quando o servidor recusa uma ação por falta de permissão no perfil, o app passa a mostrar um aviso "Sem permissão" em vez de um erro intitulado "Exceção", que parecia falha do sistema.

## [5.116.1] — 2026-10-04

### Correções

- **Kanban:** o badge de estado do cartão (nome e cor da etapa) não aparecia no app quando a etapa tinha nome composto, como "Status da OS", mesmo aparecendo no editor. Republique o projeto para aplicar.

## [5.116.0] — 2026-10-04

### Correções

- **Kanban:** a opção "Habilitar evento de ordenação" passa a funcionar: arraste o cabeçalho de uma coluna para mudar a ordem das etapas, e a nova ordem fica gravada. Antes a opção não fazia nada no app. Republique o projeto para aplicar.

## [5.115.8] — 2026-10-04

### Correções

- **Calendário:** na visão Mês do tema padrão, evento com horário aparecia como uma barra escura com o título ilegível. Agora mostra o ponto colorido, a hora e o título, como nas outras visões.

## [5.115.7] — 2026-10-04

### Correções

- **Listagens:** no PDF exportado, o `{FILTERS}` do cabeçalho passa a listar também os filtros aplicados pelo funil das colunas (ex.: "Tipo: Pessoa física"), com o mesmo texto do chip "Filtros aplicados". Antes o relatório saía filtrado sem dizer por quê.

## [5.115.6] — 2026-10-04

### Correções

- **Dashboards:** o filtro "Período mês/ano" não recortava nada numa tabela que só tem data (sem colunas de mês e ano), e os indicadores continuavam iguais ao escolher o mês. Agora ele recorta pela coluna de data da tela; sem nenhuma das duas, o app registra um aviso no log. Republique o projeto para aplicar.

## [5.115.5] — 2026-10-04

### Correções

- **Listagens:** com "Após salvar" atualizando a listagem sem recarregar, a linha nova aparecia mas o rodapé continuava com a contagem antiga (ex.: "1–9 de 9" com 10 linhas na tela). Agora o rodapé soma a linha nova. Republique o projeto para aplicar.

## [5.115.4] — 2026-10-04

### Correções

- **Formulários:** num detalhe (`<mad-detail-form>`), a coluna calculada (`evaluate`) mostrava R$ 0,00 em todas as linhas que vieram do banco ao abrir o registro para edição, e o total do rodapé também. Agora as linhas existentes já chegam calculadas. Republique o projeto para aplicar.

## [5.115.3] — 2026-10-04

### Correções

- **Listagens:** na barra de filtros, uma lista de opções sem o texto "Todos" passava a filtrar sozinha pelo primeiro valor quando outro filtro era aplicado, e a etiqueta mostrava o valor técnico (ex.: "Tipo: execucao"). Agora a lista começa em "Todos" e a etiqueta mostra o nome da opção. Republique o projeto para aplicar.

## [5.115.2] — 2026-10-04

### Correções

- **Documentos (PDF):** o cabeçalho e o rodapé do documento apareciam em uma página só (o rodapé, por exemplo, só na última). Agora se repetem em todas as páginas. Republique o projeto para aplicar.

## [5.115.1] — 2026-10-04

### Correções

- **Copilot IA:** ao responder um ranking (ex.: "Em seguida: vendedor 5…"), o assistente às vezes pedia desculpas ("Você tem razão — vou executar agora…") e fazia uma consulta que ninguém pediu. Agora a resposta termina normalmente. Republique o projeto para aplicar.

## [5.115.0] — 2026-10-04

### Correções

- **Listagens:** sem "Título da exportação", o arquivo exportado (CSV, Excel, PDF) e o cabeçalho do PDF saíam com o nome técnico da tela (ex.: `EquipamentoList`). Agora usam o título da tela (ex.: "Equipamentos"). Republique o projeto para aplicar.

## [5.114.1] — 2026-10-03

### Melhorias

- **Assistente de IA:** com modelos Claude pelo OpenRouter, o assistente passa a reaproveitar o cache entre as mensagens da conversa: a partir da segunda mensagem cada resposta custa de 5 a 7 vezes menos. Republique o projeto para aplicar.

## [5.114.0] — 2026-10-02

### Atenção

- **Dependências:** o framework passa a usar o Laravel AI 1.0 e o Laravel MCP 1.0. Republique o projeto para atualizar. Quem atualiza as dependências do app à mão precisa subir `laravel/ai` e `laravel/mcp` para `^1.0.1` no `composer.json`.

### Melhorias

- **MCP:** o servidor MCP do app aceita clientes na versão mais nova do protocolo e continua atendendo os clientes antigos.
- **Assistente de IA:** o consumo de tokens com Anthropic passa a incluir os tokens lidos do cache do prompt, como já acontecia com OpenRouter, e os tokens de raciocínio passam a ser registrados.

## [5.113.0] — 2026-10-02

### Novidades

- **Formulário:** a seção ganhou `no-header`, que esconde ícone, título e linha mesmo com título preenchido. Útil quando o formulário abre em gaveta, que já mostra o título da tela na barra de cima. Republique o projeto para aplicar.

## [5.112.1] — 2026-10-02

### Melhorias

- **Temas:** os ajustes de altura do tema passam a valer também para formulários abertos em gaveta: a barra de título da gaveta e o cabeçalho das seções com ícone ficam mais baixos com a densidade compacta. Quem não ajustar continua com as medidas de antes. Republique o projeto para aplicar.

## [5.112.0] — 2026-10-02

### Novidades

- **Temas:** a altura do cabeçalho da página, do cabeçalho e do rodapé dos cards e da barra acima das colunas da listagem agora pode ser ajustada no tema, para telas mais compactas. Quem não ajustar continua com as medidas de antes. Republique o projeto para aplicar.

### Correções

- **Listagens:** a barra acima das colunas aparecia vazia, sem nenhum botão, para perfis sem permissão de exportar ou quando nenhuma coluna podia ser ocultada. Agora ela só aparece quando tem algo dentro. Republique o projeto para aplicar.

## [5.111.3] — 2026-10-02

### Correções

- **Temas:** um tema configurado como Claro abria escuro quando o computador do usuário estava em modo escuro. Agora o app abre no modo escolhido no tema, e a troca feita no botão de sol/lua continua valendo. Republique o projeto para aplicar.

## [5.111.2] — 2026-10-02

### Correções

- **Formulários:** campos numérico, de número, de tags e de seleção saíam com altura ou cantos diferentes dos campos de texto; agora seguem o mesmo padrão, e o de número respeita o alinhamento escolhido. Republique o projeto para aplicar.
- **Formulários:** em cards lado a lado, o rodapé do card com menos conteúdo não descia até o fundo e ficava desalinhado do vizinho. Republique o projeto para aplicar.

## [5.111.1] — 2026-10-02

### Correções

- **Listagens:** com os botões de ação à esquerda, que é o padrão das listagens geradas, o botão do seletor de colunas não aparecia. Agora ele fica no cabeçalho da coluna de ações, dos dois lados. Republique o projeto para aplicar.

## [5.111.0] — 2026-10-02

### Novidades

- **Listagens:** nova opção `not-hideable` no `<mad-col>`: a coluna fica sempre visível e sai do seletor de colunas. Se alguém já tinha escondido essa coluna pelo seletor, ela volta a aparecer.

## [5.110.0] — 2026-10-02

### Novidades

- **Listagens:** nova opção `hide-below` no `<mad-col>`: a coluna some quando a tela é mais estreita que a largura informada, em pixels (ex.: `hide-below="768"` esconde no celular). Volta ao alargar a tela, e a exportação continua levando a coluna.

## [5.109.0] — 2026-10-01

### Novidades

- **Calendário:** novo atributo `event-form` no `<mad-calendar>` para ligar o formulário dos eventos. Clicar num evento abre a edição, clicar num horário vazio abre um evento novo com a data e a hora preenchidas, e a barra do calendário ganha o botão "Novo". Quando o formulário fecha, o calendário recarrega os eventos sem sair da semana em que você está.

### Correções

- **Calendário:** os botões da barra (anterior, próximo, Hoje, Dia, Semana, Mês e Lista) passam a usar a cor principal do tema, e as setas de anterior e próximo voltam a aparecer. Antes os botões saíam azuis e as setas apareciam como quadrados vazios. Republique o projeto para aplicar.
- **Calendário:** a duração de slot de 1 hora, que é o padrão, aparecia no app com divisões de 30 minutos. Agora o app mostra a mesma duração do editor. Republique o projeto para aplicar.

## [5.108.1] — 2026-10-01

### Correções

- **Listagens:** filtro marcado como obrigatório agora é respeitado. Buscar com Início preenchido e Fim vazio aponta o erro no campo e não filtra só por uma das datas; a listagem abre vazia e só busca com os obrigatórios preenchidos. Republique o projeto para aplicar.
- **Listagens:** campo de filtro escrito colado na abertura do bloco (`<mad-grid-filters style="form"><mad-date-field … />`, na mesma linha) sumia da tela. Agora aparece normalmente.
- **Reatividade (MadWire):** erro de validação não tratado numa ação passa a aparecer no campo, como no formulário, em vez da janela de erro.

## [5.108.0] — 2026-10-01

### Novidades

- **Listagens:** nova opção `row-click` no `<mad-grid>`: clicar em qualquer ponto da linha (ou do cartão) executa a primeira ação dela, como o "clique padrão" do MadBuilder 4. A ação de excluir nunca é disparada assim, e botões, links e campos da linha continuam com o próprio clique.

## [5.107.0] — 2026-09-30

### Novidades

- **Código aberto:** o Mad Framework passa a ter o código aberto, sob licença MIT, em `github.com/madbuilder-dev/mad-framework`, e é publicado no Packagist como `madbuilder/framework`.

### Correções

- **App gerado:** em telas importadas do legado, textos com letras acentuadas pouco comuns (como Ł, ħ e ı) podiam sair com caracteres trocados, e texto gravado em ISO-8859-1 perdia os acentos ao ser convertido. Republique o projeto para aplicar.

### Atenção

- **Composer:** o pacote agora se chama `madbuilder/framework`. Projetos gerados pelo MadBuilder continuam instalando sem mudança; se você edita o `composer.json` do projeto à mão, troque `"mad/framework"` por `"madbuilder/framework"` antes de rodar `composer update`.

## [5.106.0] — 2026-09-30

### Novidades

- **Multi-unidade:** novo modo em que o usuário vê os registros de todas as unidades a que tem acesso, em listagens, campos de seleção, filtros e dashboards, sem precisar trocar de unidade. Os cadastros novos continuam indo para a unidade ativa. Ligue a opção Ver todas as unidades do usuário no painel Tenancy & Banco e republique o projeto.

### Correções

- **Multi-unidade:** um formulário conseguia gravar um registro numa unidade a que o usuário não tem acesso. Agora a gravação é recusada com a mensagem "Você não tem acesso à unidade escolhida". Republique o projeto para aplicar.

## [5.105.0] — 2026-09-29

### Correções

- **Tela de login:** a imagem de fundo definida no tema não aparecia em nenhuma variante. Agora cobre a página inteira nas variantes 1 e 5, o painel da marca na 2 e a coluna da imagem nas 3 e 4, no lugar da foto e do padrão cinza de exemplo. Republique o projeto para aplicar.
- **Tela de login:** o "Logo detalhe" do tema passa a aparecer no painel da marca da variante 2, e o "Logo título detalhe" vira o ícone da marca quando o tema não tem logo grande. Republique o projeto para aplicar.
- **Tela de login:** textos do tema com ponto, como `acme.com.br`, eram tratados como chave de tradução e trocados pelo texto padrão. Agora aparecem como foram digitados. Republique o projeto para aplicar.
- **Temas:** depois de republicar com outras cores, o navegador podia continuar mostrando as cores antigas do tema até limpar o cache. Republique o projeto para aplicar.

## [5.104.4] — 2026-09-29

### Correções

- **Campos:** a opção `strip-mask` do `<mad-input-field>`, `<mad-cep-field>` e `<mad-cnpj-field>` não tinha efeito, e o CPF, o CEP e o CNPJ eram gravados com a pontuação da máscara. Agora são gravados só com números e letras, e a validação confere esse mesmo valor. Republique o projeto para aplicar.
- **Campos:** num `<mad-detail-form>`, um campo com `strip-mask` também passa a gravar as linhas sem a máscara. Republique o projeto para aplicar.
- **Campos:** ao abrir um registro para edição, o CEP e o CNPJ gravados sem máscara aparecem formatados no campo. Republique o projeto para aplicar.

### Atenção

- **Campos:** registros salvos antes desta versão em campos com `strip-mask` continuam com a pontuação no banco e passam a ser gravados sem ela quando forem editados e salvos. Regras de validação escritas para o valor com máscara (por exemplo `size:14` num CPF) precisam considerar o valor sem ela. Para exibir o valor formatado na listagem, use `transform="cpf"`, `transform="cnpj"` ou `transform="cep"` na coluna.

## [5.104.3] — 2026-09-29

### Correções

- **Formulários:** um campo único opcional, como CPF, deixado em branco num segundo cadastro dava erro de banco "Duplicate entry" ao salvar. Agora o campo vazio é gravado como vazio de verdade, e vários registros sem CPF convivem. Republique o projeto para aplicar.
- **Formulários:** quando o banco recusa um valor repetido num campo único, o formulário mostra "O campo CPF já está sendo utilizado." no próprio campo, em vez do erro técnico do banco num diálogo. Republique o projeto para aplicar.
- **Formulários:** as mensagens de validação usam o nome do campo como aparece na tela mesmo quando a tela não envia os nomes, em vez do nome da coluna. Republique o projeto para aplicar.

## [5.104.2] — 2026-09-29

### Correções

- **Formulários:** ao preencher o endereço pelo CEP, a UF era preenchida mas o município ficava vazio. Agora os dois chegam preenchidos. Republique o projeto para aplicar.
- **Campos:** um valor definido pelo código da tela num campo de seleção ficava gravado, mas o campo continuava mostrando "Selecione...". Agora a opção escolhida aparece. Republique o projeto para aplicar.

## [5.104.1] — 2026-09-29

### Correções

- **Menu:** no tema ERP, com o menu lateral recolhido, o botão de expandir sumia assim que uma tela era aberta em aba e só voltava depois de fechar todas as abas. Agora ele fica sempre visível ao lado das abas. Republique o projeto para aplicar.

## [5.104.0] — 2026-09-29

### Novidades

- **Campos:** o label de qualquer campo de formulário pode ter cor, tamanho, peso e itálico próprios: `label-color="#1E55E8"`, `label-size="14"`, `label-weight="normal|bold"` e `label-italic`.
- **Campos:** os campos de digitação e seleção podem ser destacados com cor de fundo, cor do texto, negrito e itálico: `input-bg`, `input-color`, `input-weight="bold"` e `input-italic`. O fundo vale também em campo somente leitura e no tema escuro; sem `input-color`, o texto fica escuro ou claro conforme o fundo.
- **Formulários:** `<mad-form label-color="…" label-weight="…">` aplica o estilo do label a todos os campos do formulário de uma vez. Um campo com estilo próprio continua mandando no dele.

## [5.103.2] — 2026-09-29

### Correções

- **Listagens:** em listagem que exige filtro, clicar em Buscar sem preencher nada trocava a lupa por um funil laranja e deixava a mensagem em marrom, fora das cores do tema. Agora o ícone e a mensagem continuam nas cores do tema. Republique o projeto para aplicar.

## [5.103.1] — 2026-09-29

### Correções

- **Busca global:** com as abas ligadas, escolher uma tela ou um registro na busca "Buscar em tudo" (Ctrl K) recarregava o sistema e fechava todas as abas abertas. Agora a tela abre numa aba nova, ou volta para a aba em que já está aberta. Republique o projeto para aplicar.

## [5.103.0] — 2026-09-29

### Novidades

- **Listagens:** o PDF exportado pode sair em retrato ou paisagem. A orientação vem da página ou do projeto (`"orientation"` no `exportPdfBands()` ou no `pdf-export.json`); sem configuração continua em paisagem.
- **Listagens:** a linha abaixo do cabeçalho padrão do PDF aceita outra cor ou pode ser removida (`palette.headerRule`, com uma cor ou `none`), na página ou no projeto.

### Correções

- **Listagens:** o PDF exportado deixava um espaço em branco entre o cabeçalho padrão e a tabela, e o título ficava colado na borda do papel. Republique o projeto para aplicar.

## [5.102.1] — 2026-09-29

### Correções

- **Chat interno:** a janela de conversas abria sozinha ao entrar no sistema e a cada recarga da página. Agora ela começa fechada e abre pelo ícone de conversas do topo ou ao clicar no aviso de mensagem nova. Republique o projeto para aplicar.

## [5.102.0] — 2026-09-29

### Novidades

- **Filtros:** os filtros da listagem e do painel aceitam `apply-label` e `clear-label` para trocar o texto de "Aplicar" e "Limpar tudo" (por exemplo, `apply-label="Buscar"`), e `no-refresh` para esconder "Atualizar".
- **Filtros:** no estilo Formulário, `no-header` tira a linha com ícone e título "Filtros", deixando só os campos e os botões.

### Melhorias

- **Filtros:** o estilo Formulário deixou de sobrar espaço vazio entre os botões e a listagem.

### Correções

- **Listagens:** com a exportação desligada (`no-export`), o app só escondia o botão; uma chamada direta ainda exportava os dados. Agora a exportação é recusada. Republique o projeto para aplicar.
- **Filtros:** o título dos filtros configurado como texto traduzível não aparecia; a tela mostrava sempre "Filtros". Republique o projeto para aplicar.

## [5.101.1] — 2026-09-29

### Correções

- **Banco de dados:** em apps publicados no MadCloud com PostgreSQL, salvar ou filtrar um campo sim/não (coluna booleana) falhava com "column is of type boolean but expression is of type integer", embora funcionasse no Teste Online. Republique o projeto para aplicar.

## [5.101.0] — 2026-09-29

### Novidades

- **Campos:** `<mad-select-field>` ganha a opção `multiple`: a lista vira caixas de seleção e o campo guarda vários itens. A propriedade da tela precisa ser uma lista (array).
- **Filtros:** nos filtros do painel e da listagem, um select com `multiple` mostra os registros de qualquer item marcado. O filtro exibe os nomes escolhidos (ou "3 selecionados") e aceita a lista na URL, como `?status=A,F`.

### Correções

- **Filtros:** no filtro do painel, escolher um item na lista de um campo de seleção podia fechar a janela do filtro antes de aplicar. Republique o projeto para aplicar.

## [5.100.2] — 2026-09-28

### Correções

- **Campos:** no Safari, clicar num campo de moeda já preenchido não selecionava o valor, e o que se digitava era somado a ele (1.234,50 virava 12.345,09). O mesmo acontecia nas células de valor editáveis de listagens, detalhes e planilhas, e no campo de código de verificação. Agora o valor é selecionado ao clicar, como no Chrome. Republique o projeto para aplicar.

## [5.100.1] — 2026-09-28

### Correções

- **Campos:** no `<mad-money-field>`, clicar antes do número e digitar misturava os dígitos com o valor que já estava no campo (digitar 50012 mostrava 500.000,12). Acontecia sempre com o campo alinhado à direita. Agora o dígito entra sempre pela direita, como numa calculadora. Republique o projeto para aplicar.

## [5.100.0] — 2026-09-28

### Novidades

- **Campos:** `<mad-numeric-field>`, `<mad-money-field>` e `<mad-number-field>` ganham `align` (`left`, `center` ou `right`) para alinhar o valor digitado, por exemplo valores à direita. Sem a opção, o campo continua alinhado à esquerda.

## [5.99.0] — 2026-09-28

### Novidades

- **Listagens:** ao voltar de um formulário aberto em página inteira, a listagem reabre na mesma página, com a mesma ordenação, busca e filtros, e a linha salva aparece destacada. Abrir a listagem pelo menu continua começando do início. No código: `->redirect('ClienteList', ['_mad_return' => $this->recordId])`. Republique o projeto para aplicar.

### Melhorias

- **Listagens:** depois de salvar num formulário em gaveta, a linha atualizada rola até ficar visível na listagem.

## [5.98.2] — 2026-09-28

### Correções

- **Formulário:** uma seção sem título deixava uma faixa vazia com uma linha no topo e empurrava os campos para baixo. Agora a seção sem título, subtítulo nem ícone aparece só com os campos. Republique o projeto para aplicar.

## [5.98.1] — 2026-09-28

### Correções

- **Copilot IA:** com o Mad Coding Plan, a resposta às vezes parava logo depois da consulta, só com um aviso como "Agora listo as OS atrasadas…" na tela. Agora o Copilot tenta de novo e, se não conseguir, avisa. Republique o projeto para aplicar.
- **Copilot IA:** uma resposta completa que terminava com algo "ainda" em aberto ou "falta de peça" fazia o Copilot emendar frases como "Você está certo — fechei o turno errado". Isso não acontece mais. Republique o projeto para aplicar.

## [5.98.0] — 2026-09-28

### Novidades

- **Copilot IA:** passa a respeitar o perfil do usuário: responde só sobre as tabelas que o MCP do projeto libera para os perfis dele (Studio › MCP › Permissões), com as colunas e os registros que cada perfil pode ver. Pedido fora do acesso recebe "Você não tem acesso a …". Republique o projeto para aplicar.
- **Meus Dashboards:** um painel compartilhado mostra a cada pessoa só o que o perfil dela alcança; o widget de uma tabela que ela não acessa aparece com o aviso de acesso, sem os dados.

### Correções

- **Copilot IA:** consultas do assistente liam tabelas, colunas escondidas e registros de outros usuários ou de outras unidades que o perfil não acessava; e-mails e outros dados marcados como pessoais apareciam por inteiro. Agora seguem as mesmas regras do MCP. Republique o projeto para aplicar.
- **Copilot IA:** conversas do Copilot de outros usuários e mensagens do chat interno não podem mais ser consultadas pelo assistente.
- **MCP:** as permissões marcadas por perfil no Studio não valiam no app, e as tools publicadas não apareciam para ninguém. Agora cada perfil usa exatamente as tools liberadas para ele.
- **Logs:** o log SQL opcional gravava o hash da senha ao trocá-la e o segredo do 2FA ao ativá-lo. Agora essas instruções não são gravadas, e senhas, tokens e chaves de outras tabelas aparecem mascarados. Republique o projeto para aplicar.

### Atenção

- **MCP:** projetos com MCP já publicado precisam publicar o MCP de novo (Studio › MCP › Publicar) e republicar o projeto para que as permissões de cada perfil passem a valer no app.
- **Copilot IA:** em projeto sem MCP publicado, o assistente passa a responder só ao perfil Admin; os demais perfis recebem o aviso de que não têm acesso aos dados. Para liberá-los, configure Studio › MCP (Entidades e Permissões), publique e republique o projeto; células em "herda" não liberam nada.
- **MCP:** a exclusão por tool deixa de exigir o perfil administrador: vale o que estiver marcado para o perfil em Studio › MCP › Permissões. Confira os perfis que têm "del_" liberado.

## [5.97.0] — 2026-09-28

### Novidades

- **Site público:** páginas do site podem receber formulários do visitante (pedido, orçamento, agendamento) quando a página declara que aceita envio; antes o envio dava erro 405. O formulário precisa de `@csrf` e há limite de envios por visitante.

### Correções

- **Listagem (grid):** filtro com opções que não são valores da coluna, como faixas de estoque ("baixo", "zerado"), não esvazia mais a lista. Republique o projeto para aplicar.
- **Campos:** listas de opções escritas como `[['value' => …, 'label' => …]]` deixam de aparecer vazias nos campos de escolha. Republique o projeto para aplicar.

## [5.96.21] — 2026-09-28

### Correções

- **Logs:** o registro de requisições guardava senhas e cookies em texto. Agora esses valores são mascarados. Republique o projeto para aplicar.

### Atenção

- **Logs:** registros de requisição gravados antes desta versão podem conter senhas e cookies. A tela Logs › Log de request não tem opção de limpeza: apague os registros antigos direto no banco do app (tabela `mad_log_request`).

## [5.96.20] — 2026-09-28

### Correções

- **Copilot IA:** o assistente conseguia consultar tabelas internas com senhas e tokens. Agora essas tabelas são protegidas. Republique o projeto para aplicar.

## [5.96.19] — 2026-09-28

### Correções

- **Listagens:** os filtros aplicados mostravam datas e valores como "a partir de 2026-01-01" e "5000". Agora aparecem no formato da tela, como "a partir de 01/01/2026" e "5.000,00", também no Filtro avançado. Republique o projeto para aplicar.
- **Formulários:** a consulta de CEP e CNPJ apagava o número, o complemento ou outro campo já digitado quando o serviço não trazia aquele dado. Agora o que você digitou é mantido. Republique o projeto para aplicar.
- **Permissões:** com "Ações sem permissão" em "Desabilitados, com dica", a dica "Sem permissão para …" não aparecia. Agora aparece ao passar o mouse, ao focar pelo teclado e ao tocar no botão. Republique o projeto para aplicar.

## [5.96.18] — 2026-09-28

### Correções

- **PDV:** o cupom impresso saía com "Venda nº undefined" e a data em formato técnico. Agora mostra o número da venda e a data e a hora no formato brasileiro, no horário da loja. Republique o projeto para aplicar.

## [5.96.17] — 2026-09-28

### Correções

- **Site público:** o menu do topo quebrava em duas linhas, com a marca de um lado e os links embaixo, e a caixa "Li e aceito os termos" do cadastro aparecia como uma barra da largura do formulário. Agora o menu fica numa linha só e a caixa aparece ao lado do texto. Republique o projeto para aplicar.

## [5.96.16] — 2026-09-28

### Correções

- **Gantt:** a dica que aparece ao passar o mouse numa tarefa mostrava "CRITICAL" e "MILESTONE" em inglês. Agora aparece no idioma do app ("Crítica", "Marco"). Republique o projeto para aplicar.

## [5.96.15] — 2026-09-28

### Correções

- **Várias unidades:** códigos únicos, como nº da OS, código de status, SKU e matrícula, eram cobrados entre todas as unidades: uma unidade não conseguia usar um código que outra já usava, e o aviso "já está sendo utilizado" revelava o que existia em outro cliente. Agora o código é único dentro de cada unidade. Republique o projeto para aplicar.
- **Várias unidades:** formulários e a API aceitavam, num campo de referência, um registro de outra unidade. Agora recusam. Republique o projeto para aplicar.
- **Combos e buscas:** a busca de um combo podia ser reaproveitada em outro campo, ou por outro usuário, para listar registros fora do filtro da tela. Agora cada busca vale só para o campo e o usuário que a receberam. Republique o projeto para aplicar.

## [5.96.14] — 2026-09-28

### Correções

- **Copilot IA:** o cabeçalho do assistente mostrava "mad_framework" no lugar do nome do sistema. Agora mostra o nome do app, o mesmo da aba do navegador, e a Central de Comando também chama o sistema pelo nome. Republique o projeto para aplicar.
- **Copilot IA:** entrar com o mesmo usuário em outro navegador ou aparelho desconectava o Copilot da primeira sessão ("Erro de conexão"). Agora cada sessão tem o próprio acesso, e "Sair" encerra só o da sessão que saiu. Republique o projeto para aplicar.

## [5.96.13] — 2026-09-28

### Correções

- **Documentos (PDF):** no PDF do app, a faixa de cabeçalho cobria o começo do conteúdo da página, e a de rodapé cobria o fim. Agora a página reserva o espaço de cada faixa, como na prévia do editor. Se você colocou espaço extra para compensar, pode removê-lo. Republique o projeto para aplicar.

## [5.96.12] — 2026-09-28

### Correções

- **Aprovações:** na Fila de Aprovação, as datas apareciam como "2026-09-27T23:15:00.000000Z" e o rodapé continuava "1–1 de 1" depois que a última pendência saía da lista. Agora a data aparece como 27/09/2026 23:15 e o rodapé acompanha a lista. Republique o projeto para aplicar.
- **Listagens:** colunas de data sem formato mostravam o valor técnico "2026-09-27T23:15:00.000000Z", na tela e na planilha exportada. Agora mostram data e hora legíveis no fuso do app. Republique o projeto para aplicar.
- **Traduções:** os rótulos dos selos, como o "Sim"/"Não" da coluna Ativo, ficavam em português no app traduzido. Agora aparecem no idioma de quem usa o app. Republique o projeto para aplicar.

## [5.96.11] — 2026-09-28

### Correções

- **Login:** quem entrava sem "Tela inicial" no cadastro, como o usuário convidado pelo administrador da unidade ou a conta criada pelo cadastro do site, caía numa página em branco. Agora abre a primeira tela do menu que a pessoa pode acessar; sem nenhuma, aparece uma página de boas-vindas explicando o que fazer. Republique o projeto para aplicar.

## [5.96.10] — 2026-09-28

### Correções

- **Copilot IA:** perguntas sobre os dados do sistema, como "Quais OS estão atrasadas?", passam a ser respondidas consultando o banco, sem precisar pedir. Antes o assistente recusava ou respondia com outra pergunta. Republique o projeto para aplicar.
- **Copilot IA:** ao comentar um resultado, o assistente citava ordens de serviço, clientes, técnicos e números que não existem. Agora comenta só o que a consulta trouxe. Republique o projeto para aplicar.
- **Copilot IA:** sem o manifesto do projeto, o assistente se apresentava como o "MiniERP de demonstração" e ignorava as tabelas do sistema. Agora trabalha com as tabelas do próprio sistema. Republique o projeto para aplicar.
- **Copilot IA:** valores de indicadores e widgets saíam em formatos como "R$ 0.02 mi". Agora saem no formato brasileiro, como R$ 20.884,00. Republique o projeto para aplicar.
- **Copilot IA:** "hoje", "este mês" e "mês passado" usavam uma data errada. Agora seguem a data e o fuso do sistema, e widgets salvos com esses períodos continuam certos nos dias seguintes. Republique o projeto para aplicar.
- **Copilot IA:** uma consulta sem resultado fazia o assistente tentar várias vezes e parar sem resposta. Agora ele responde que não há registros. Republique o projeto para aplicar.

## [5.96.9] — 2026-09-28

### Correções

- **Instalação:** abrir o projeto baixado antes de instalar mostrava "Server Error". Agora qualquer página leva ao instalador (`/install`), e o projeto traz um `LEIA-ME.md` com os passos. Baixe o projeto de novo para aplicar.
- **App gerado:** rodando com `php artisan serve`, cada página aberta sem parâmetros na URL mostrava `Undefined array key "QUERY_STRING"` no terminal e ficava fora do log de requisições. Agora é registrada normalmente. Republique o projeto para aplicar.
- **Instalação:** o projeto dizia aceitar PHP 8.3, mas precisa de PHP 8.4.1 ou superior (no 8.3 as páginas davam erro). O requisito agora bate com o guia de instalação.

## [5.96.8] — 2026-09-28

### Correções

- **Site público:** campos a mais do formulário de contato, como "Tipo de serviço", eram descartados sem aviso. Agora aparecem em "Outras informações" na ficha do contato (Administração › Site › Contatos) e no aviso por e-mail. Republique o projeto para aplicar.

## [5.96.7] — 2026-09-27

### Correções

- **Kanban:** arrastar um card falhava com erro de coluna "ordem" em quadros cuja tabela não tem essa coluna, e os contadores das colunas ficavam errados. Agora o card muda de etapa normalmente. Republique o projeto para aplicar.
- **Kanban:** quadros cuja tabela de etapas tem a chave com outro nome (ex.: `codigo`) mostravam as colunas vazias. Agora os cards aparecem e podem ser arrastados. Republique o projeto para aplicar.
- **Kanban:** um erro ao mover card mostrava a mensagem técnica do banco para o usuário. Agora mostra um aviso amigável. Republique o projeto para aplicar.
- **Listagens, formulários, agenda e Gantt:** um erro do banco ao excluir ou salvar aparecia com a mensagem técnica, incluindo o caminho do arquivo do banco. Agora aparece um aviso amigável. Republique o projeto para aplicar.

## [5.96.6] — 2026-09-27

### Correções

- **Sistemas com várias unidades:** opções de filtro, nomes nas listagens, painéis, histórico de alterações, conciliação e o assistente de IA podiam mostrar dados de outras unidades. Agora cada unidade vê só os próprios dados. Republique o projeto para aplicar.

### Atenção

- **Assistente de IA:** em sistemas com várias unidades, alguns widgets criados pelo assistente podem passar a mostrar um aviso em vez dos números. Peça ao assistente para recriá-los.

## [5.96.5] — 2026-09-27

### Correções

- **Dashboard:** em sistemas com várias unidades, os gráficos e as tabelas dinâmicas do painel somavam registros de outras unidades. Agora cada unidade vê só os próprios números, e registros excluídos deixam de contar. Republique o projeto para aplicar.
- **Kanban:** em sistemas com várias unidades, o número e o total no topo de cada coluna somavam os cards das outras unidades até você arrastar um card. Agora batem com os cards da coluna. Republique o projeto para aplicar.

## [5.96.4] — 2026-09-27

### Correções

- **Listagem (grid):** o filtro "Busca no banco" (na coluna e no filtro avançado) e a busca da edição direto na célula não encontravam nada e sempre mostravam "Nenhum registro encontrado". Agora buscam normalmente. Republique o projeto para aplicar.
- **Listagem (grid):** a lista de opções aberta dentro do filtro de coluna ficava por baixo da janela do filtro, e a primeira opção não podia ser clicada. Agora ela abre por cima. Republique o projeto para aplicar.
- **Listagem (grid):** em projetos com banco SQLite, os filtros de período deixavam de fora o dia inicial em colunas de data: "a partir de 01/01/2026" não trazia o cadastro de 01/01/2026, e "Hoje" vinha vazio. Agora o primeiro e o último dia entram. Republique o projeto para aplicar.
- **Gantt:** salvar uma tarefa na gaveta não atualizava a barra no cronograma até recarregar a tela. Agora a barra muda na hora. Republique o projeto para aplicar.
- **Campos:** no celular, o campo de assinatura abria sozinho em tela cheia ao carregar o formulário, até o de uma gaveta fechada. Agora mostra "Clique para assinar" e abre em tela cheia só quando você toca nele. Republique o projeto para aplicar.

## [5.96.3] — 2026-09-26

### Melhorias

- **Listagem (grid):** os ícones de ordenar e de filtrar ficam junto do título da coluna. Em colunas alinhadas à direita, como valores, eles não aparecem mais do outro lado do cabeçalho. Republique o projeto para aplicar.

## [5.96.2] — 2026-09-26

### Correções

- **Listagem (grid):** o botão de excluir definido com `variant="danger"`, o padrão das listagens geradas, voltou a aparecer em vermelho. Republique o projeto para aplicar.
- **Formulário:** o botão com `close-drawer` sem valor, como o "Cancelar", não fazia nada; agora fecha a gaveta ou o modal onde está, e botões que abrem outra tela de dentro de uma gaveta também a fecham. Republique o projeto para aplicar.
- **Rotas:** redirecionar uma ação para um endereço pronto, em vez do nome de uma tela, abria uma página inexistente; agora navega para o endereço. Republique o projeto para aplicar.

## [5.96.1] — 2026-09-25

### Melhorias

- **Listagem (grid):** o limite de linhas do PDF acompanha a memória disponível no servidor: cerca de 10 mil linhas no MadCloud e várias dezenas de milhares em servidores com mais memória. Republique o projeto para aplicar.

## [5.96.0] — 2026-09-25

### Melhorias

- **Listagem (grid):** exportar para PDF ficou dezenas de vezes mais rápido e usa muito menos memória: mil linhas saem em menos de um segundo e 10 mil em poucos segundos, com o mesmo visual de antes. Republique o projeto para aplicar.

### Atenção

- **Listagem (grid):** o PDF passa a ter um limite de linhas. Acima dele, a tela avisa e sugere exportar para Excel ou CSV, que não têm limite.

## [5.95.0] — 2026-09-25

### Melhorias

- **Listagem (grid):** exportar para Excel e CSV ficou muito mais rápido e leve: 100 mil linhas saem em poucos segundos, sem estourar a memória do servidor. Com quebra, os grupos saem em ordem crescente da chave do grupo. Republique o projeto para aplicar.

### Atenção

- **Listagem (grid):** a exportação para Excel usa uma biblioteca nova. Sistemas atualizados só pela Central de Comando mostram um aviso ao exportar para Excel até o projeto ser republicado.
- **Projeto:** o framework não traz mais a biblioteca `phpoffice/phpspreadsheet`. Se o código do seu projeto usa essa biblioteca, adicione o pacote ao projeto antes de republicar.

## [5.94.1] — 2026-09-25

### Correções

- **Listagem (grid):** um texto com ponto, como o documento `4555.5555`, saía no Excel como número e aparecia `4555,5555`. Agora sai igual à tela; colunas numéricas do banco continuam somáveis. Republique o projeto para aplicar.

## [5.94.0] — 2026-09-25

### Novidades

- **Listagem (grid):** filtro avançado — o usuário do sistema monta o próprio filtro escolhendo coluna, operador e valor, combina as condições com "todas" ou "qualquer uma" e vê quantos registros vai trazer antes de aplicar. Ligue na grade com `<mad-custom-filters>` ou pelo painel "Filtro avançado" do editor.
- **Listagem (grid):** filtros salvos por usuário, com opção de compartilhar com a equipe e de abrir a tela já filtrada.
- **Listagem (grid):** períodos prontos para datas (hoje, este mês, últimos 30 dias e outros), recalculados a cada uso, inclusive em filtros salvos. Republique o projeto para aplicar.

### Melhorias

- **Listagem (grid):** o filtro de coluna passa a funcionar em colunas de relação com dois ou três níveis, como `{cidade->estado->nome}`, que antes eram ignoradas.

## [5.93.1] — 2026-09-25

### Correções

- **Segurança:** um usuário logado podia forjar o envio de uma tela para liberar a edição de colunas bloqueadas numa listagem, trocar a tabela exibida ou pesquisar em colunas que a tela não mostra. Agora esse envio é recusado. Republique o projeto para aplicar.

## [5.93.0] — 2026-09-24

### Melhorias

- **Listagem (grid):** o Excel e o CSV exportados mostram cada coluna como a tela: CPF, CNPJ, CEP e telefone saem com a máscara, o badge sai com o rótulo e o formatador do projeto é respeitado. Republique o projeto para aplicar.
- **Listagem (grid):** no Excel, colunas de valor viram número com o formato da coluna (casas decimais, `R$`, `%`), datas viram data de verdade e os totais podem ser somados e usados em fórmulas.
- **Listagem (grid):** o alinhamento definido na coluna (esquerda, centro, direita) passa a valer também no cabeçalho e nas linhas de total do Excel.

### Correções

- **Listagem (grid):** CNPJ, telefone e códigos só com números apareciam no Excel em notação científica (`1,23457E+13`) ou sem o zero à esquerda, e textos começando com `=` viravam fórmula. Agora saem como texto, exatamente como na tela.

## [5.92.1] — 2026-09-24

### Correções

- **Listagem (grid):** no PostgreSQL, a busca geral e o filtro de coluna de texto diferenciavam maiúsculas de minúsculas (`b1` não encontrava `B1`), inclusive em colunas de relação. Agora ignoram, como já acontecia no MySQL. Republique o projeto para aplicar.
- **Filtros:** os filtros de texto do painel de filtros (contém, começa com, termina com) também passam a ignorar maiúsculas e minúsculas no PostgreSQL.
- **PDV:** a busca de produto por nome, código ou código de barras passa a ignorar maiúsculas e minúsculas no PostgreSQL.

## [5.92.0] — 2026-09-24

### Novidades

- **Traduções:** o título da tela passa a seguir o idioma do usuário quando o texto tem tradução cadastrada em Traduções no MadBuilder. Sem tradução, continua o texto original.

### Correções

- **Traduções:** o rótulo do grupo de ações da grid (`<mad-action-group>`), o título e a descrição dos passos do `<mad-wizard>`, o rótulo de `<mad-step>` e o título de `<mad-timeline-item>` saíam vazios quando escritos como chave de tradução (`:label="__('grupo.chave')"`). Agora mostram o texto traduzido.

## [5.91.0] — 2026-09-24

### Novidades

- **Documento (PDF):** `<mad-doc-page size="custom" width-mm="100" height-mm="150">` gera a página com a largura e a altura informadas, em milímetros (de 10 a 2000), para etiquetas, cupons, envelopes e formulários fora do padrão. Para virar a folha, troque os dois números.

### Melhorias

- **Login:** quem tem acesso a uma única unidade entra direto nela, sem o modal "Escolha a unidade". Com mais de uma, o modal já abre com a unidade principal do usuário marcada; a escolha de empresa também.

## [5.90.0] — 2026-09-23

### Novidades

- **Combo:** `empty-as` em `<mad-dbcombo-field>`, `<mad-dbunique-search-field>`, `<mad-dbradio-field>`, `<mad-seek>` e `<mad-seek-field>` escolhe o que gravar quando o campo fica em branco: `null` (padrão), `zero` ou `empty`.
- **Campos:** `<mad-seek>` aceita `order-by`, `:filters` e `:query` para definir a ordem e restringir os registros listados na janela de busca.
- **Campos:** `<mad-seek create="PessoaForm::onShowFromSeek">` põe um botão "Novo" ao lado de "Buscar" e no topo da janela de busca: ele abre o cadastro por cima da tela e, ao salvar, o registro novo volta selecionado no campo, com o texto e os campos preenchidos pelo `<mad-fill>`. O rótulo e o ícone saem de `create-label` e `create-icon`.
- **Combo:** `<mad-dbcombo-field create="PessoaForm::onShowNovaPessoa">` (e `<mad-dbunique-search-field>`) põe um botão "+" ao lado do combo: ele abre o cadastro por cima da tela e, ao salvar, o registro novo volta selecionado no combo. Aceita `create-label`, `create-icon` e parâmetros fixos em `:create-params`.
- **Listagem (grid):** `<mad-grid selectable>` põe uma caixa de seleção em cada linha, "marcar todos" da página e um contador; a seleção continua ao trocar de página, buscar, filtrar e reabrir a tela.
- **Listagem (grid):** `<mad-bulk-action>` cria botões de ação em lote que chamam um método do grid com os registros marcados (`method=`) ou abrem outra tela recebendo-os (`target=`); outra tela lê a seleção com `MadGridSelection::get()`.
- **Permissões:** uma tela pode abrir sem login declarando `protected static bool $public = true;`, como a página pública do MadBuilder 4.0 (portal do cliente, formulário para visitantes). O visitante a vê na largura toda, sem o menu do app, e o resto do sistema continua exigindo login.
- **Permissões:** a tela pública pode ter uma porta própria, `publicGuard()`, conferida ao abrir a tela e a cada ação dela; quem não passa é levado à tela que ela indicar, como o login do portal.
- **Login:** uma tela pública pode aparecer como link na tela de login do app declarando `protected static string $loginLink = 'Área do Cliente';`, com ícone e ordem opcionais, como o "Mostrar na tela de login" do MadBuilder 4.0.
- **Documento (PDF):** um documento também pode abrir sem login com `protected static bool $public = true;` e ter a porta `publicGuard()`, que recebe o registro pedido; sem a marca, continua exigindo login.
- **Mensagens:** `MadMessage::prompt()` abre um diálogo que pede valores antes de executar a ação — texto, número, data, hora ou lista de opções, com campos obrigatórios —, e o valor digitado chega à ação como um campo do formulário da tela. Serve, por exemplo, para perguntar o percentual ou a data antes de uma ação em lote.
- **Layout:** uma gaveta ou modal pode atualizar a tela que ficou por trás depois de salvar ou excluir, com `->refreshScreen('TelaMae')` na resposta (ou `->refreshScreen()`, sem nome, para as telas de trás). Ex.: salvar um item na gaveta e ver o total do pedido e a lista embutida já atualizados; tela fechada não é afetada.
- **Layout:** `<mad-transporter lazy>` só carrega a tela embutida quando ela aparece: numa aba, ao abrir a aba. Sem `lazy`, continua carregando junto com a página.
- **Layout:** `<mad-transporter header="title">` mostra o título da tela embutida numa linha compacta; `header="full"` mantém o cabeçalho completo dela.

### Melhorias

- **Layout:** a tela embutida com `<mad-transporter>` não repete mais o cartão, o ícone, o título e o breadcrumb de página dentro da outra; os botões dela, como "Novo", continuam. Para o cabeçalho completo, use `header="full"`.

### Correções

- **Listagem (grid):** filtros em painel lateral (`style="drawer"`) não filtravam: ao aplicar, a listagem recarregava sem os valores digitados. Vale também para os filtros de dashboard, kanban e calendário nesse estilo.
- **Filtros:** campos numérico, monetário, de rádio, data e hora, hora, texto longo, cor e as buscas de banco com rádio, marcação ou seleção múltipla sumiam da barra e do formulário de filtros (`<mad-grid-filters>` e afins). Agora aparecem e o valor chega às ações da tela, inclusive às ações em lote.
- **Combo:** salvar com combo, rádio ou busca de banco opcional em branco falhava com "invalid input syntax for type integer" (PostgreSQL) ou "Incorrect integer value" (MySQL). O campo vazio agora grava vazio (NULL).
- **Formulário:** com um formulário aberto por cima de uma listagem e campos de mesmo nome nos dois, o erro de validação aparecia no filtro da listagem, não no campo do formulário.
- **Kanban:** a busca das telas de kanban geradas pela plataforma era ignorada e o quadro mostrava todos os cards.
- **Calendário:** a busca das telas de calendário geradas pela plataforma era ignorada e todos os eventos apareciam.
- **Documento (PDF):** tabelas e repetições de documentos publicados com `:criteria` ignoravam o filtro e listavam todos os registros.
- **Detalhe:** a coluna de outra tabela (ex.: "Produto") ficava vazia na linha recém-adicionada até o registro ser salvo.
- **Detalhe:** um botão com `mad:click` entre os campos do detalhe chamava o método sem os valores da linha em edição — o método só enxergava os campos do formulário principal.
- **Campos:** no campo de busca com janela (`<mad-seek>`), o botão Selecionar falhava com "Classe inválida" e o registro escolhido não chegava ao campo.
- **Rotas:** abrir uma tela pelo nome da classe (ex.: `Mad.go('ContaPagarList')`) dava "página não encontrada"; agora abre, igual ao endereço amigável.
- **Layout:** a tecla Esc não fechava a gaveta nem a janela modal. Agora fecha a de cima, sem atropelar um calendário, combo ou confirmação aberta por cima.
- **Layout:** numa tela aberta em gaveta ou modal, o que ela mudava na tela ao abrir (esconder um botão, trocar um texto) acontecia na tela de fundo, e não na gaveta.
- **Layout:** numa ação que envia a resposta com `emit()` em vez de devolvê-la, só a mensagem chegava à tela: `closeDrawer()` e `closeModal()` não fechavam a gaveta ou o modal, e o resto do que a ação mudou não aparecia.
- **Campos:** o campo de busca com janela (`<mad-seek>`) numa grade de 3 colunas não mostrava o registro escolhido: o texto ficava escondido atrás dos botões.
- **Formulário:** na lista de registros para marcar (`<mad-dbchecklist-field>`), a coluna de outra tabela (ex.: `{fornecedor->nome}`) aparecia vazia.
- **Listagem (grid):** ao exportar uma listagem ou relatório para CSV, Excel ou PDF, a janela "Exportação concluída" mostrava o tamanho do arquivo como "0 B", embora o arquivo baixado estivesse completo.
- **Layout:** quando a tela era atualizada (por exemplo, depois de salvar um item numa gaveta), ela voltava para a primeira aba e para o topo. Agora fica na aba e na posição em que o usuário estava, inclusive nas telas embutidas.
- **Layout:** em `<mad-transporter>`, parâmetros passados como array PHP (`:params="['pedido_id' => $id]"`) eram ignorados e a tela embutida abria sem eles. Agora vale array ou `json_encode([...])`, e um valor que não pode ser lido gera um aviso no console do navegador.
- **Formulário:** ler com `$data->campo` um campo que não veio preenchido (rádio sem opção marcada, campo desabilitado, busca ao abrir a listagem) derrubava a tela com "Undefined property"; agora vale `null`, como no 4.0, e o que é gravado não muda.
- **Formulário:** a data lida com `$this->form->get('campo')` de um `<mad-date-field>` ou `<mad-datetime-field>` vinha como digitada (`24/09/2026`), e não no formato do banco como em `getData()`: `new DateTime(...)` falhava com dia acima de 12 e, nos demais, trocava dia e mês sem avisar. Agora `get()` devolve a data no formato do banco (`2026-09-24`), igual a `getData()`.

### Atenção

- **Combo:** se um desses campos de banco aponta para uma coluna de texto que não aceita vazio (`NOT NULL`), salvar em branco passa a ser recusado pelo banco — antes gravava texto vazio. Use `empty-as="empty"` no campo para manter o comportamento antigo.
- **Formulário:** código que convertia a data lida com `$this->form->get('campo')` do formato de exibição — `DateTime::createFromFormat('d/m/Y', ...)`, `explode('/', ...)` ou a troca de posição do dia e do ano — passa a receber `2026-09-24` e precisa sair. O valor que a ação `mad:change` do próprio campo recebe continua como digitado.

## [5.89.0] — 2026-09-23

### Novidades

- **Formulários:** campos e seções (`<mad-form-section>`) aceitam `disabled-when`, `readonly-when`, `required-when` e `visible-when` para mudar de estado conforme o valor de outro campo do formulário, sem JavaScript. Exemplo: `disabled-when="{tipo} != 2"`. Campo desabilitado não é enviado ao salvar; escondido ou somente leitura continua sendo enviado.

### Correções

- **Campos:** a lista de opções desabilitada continuava com aparência de habilitada; agora aparece esmaecida, como os outros campos desabilitados.

## [5.88.0] — 2026-09-22

### Novidades

- **Campos:** novo atributo `label-gap` em todo campo de formulário — a distância vertical entre o label e o campo (vale também entre o campo e a dica). Número = pixels (`label-gap="8"`); qualquer unidade CSS é aceita.
- **Formulários:** `<mad-form label-gap="8">` aplica a distância a todos os campos do formulário de uma vez. Um campo com `label-gap` próprio continua mandando no dele.
- **Temas:** a distância label↔campo virou variável de tema (`--mad-label-gap`, padrão 5px) e pode ser ajustada para o app inteiro no editor de temas do MadBuilder.

## [5.87.1] — 2026-09-21

### Correções

- **Usuários:** na lista de usuários (e na de usuários da unidade), a ação da linha passa a se chamar "Reenviar convite" enquanto o convite está pendente, igual ao botão do cadastro; nos demais casos continua "Enviar link para definir a senha".
- **Usuários:** depois de copiar o link, ativar/desativar ou clonar alguém pela lista, a linha atualizada mostrava a coluna Senha em branco até recarregar a página.

## [5.87.0] — 2026-09-21

### Novidades

- **Usuários:** o cadastro em Administração › Usuários ganhou a opção "Convidar por e-mail": a pessoa recebe um link, define a própria senha e entra. O link vale 7 dias e só funciona uma vez; a lista mostra "Convite pendente" até isso acontecer.
- **Usuários:** ações "Reenviar convite" / "Enviar link para definir a senha" e "Copiar link de convite" na lista e no cadastro — o link copiado serve para apps sem e-mail configurado ou para enviar por outro canal. Vale também para o administrador da unidade.
- **Autenticação:** política de senha em Preferências › Segurança › Senha e Acesso: "Exigir troca de senha no primeiro acesso" (para senha definida pelo administrador) e "Validade da senha (dias)". Ao entrar, a pessoa vê a janela "Trocar senha" com o motivo e só continua depois de definir uma senha nova, diferente da atual.
- **Usuários:** caixa "Exigir troca de senha no próximo acesso" no cadastro, para forçar uma pessoa específica; a coluna Senha da lista mostra Convite pendente, Troca exigida, Expirada ou OK.
- **Preferências:** o texto do e-mail de convite pode ser editado em Preferências › E-mail › Convite (seção "Convite de usuário").

### Melhorias

- **Autenticação:** senha que expira no meio de uma sessão longa encerra a sessão e o login avisa o motivo.
- **Usuários:** clonar um usuário não copia mais a senha do original — o clone nasce sem senha própria, pronto para receber o convite.

### Correções

- **Autenticação:** a tela "Redefinir senha" só mostra "Criar conta" quando o auto-cadastro está ligado; antes o botão levava a um formulário que sempre recusava.

## [5.86.0] — 2026-09-20

### Novidades

- **Menu:** Administração ganhou dois grupos — **SaaS** (Planos, Módulos, Assinaturas, Extras, Cobrança) e **Site** (Contatos, Blog). A lista de telas soltas ficou curta e as configurações do produto e do site público passaram a ter endereço próprio. Republique o projeto ou sincronize o menu na Central de Comando para aplicar.

### Melhorias

- **Menu:** ao abrir uma tela pelo endereço (atualizar a página ou favorito), o menu marca a tela atual e abre o grupo onde ela está.
- **Menu:** a seta do grupo gira ao abrir e ao fechar.
- **Menu:** no modo só menu superior, as telas que ficam dentro de um grupo aparecem no buscador de aplicativos, com o nome do grupo acima; buscar "SaaS" encontra as telas dele.

### Correções

- **Menu:** clicar num grupo do menu lateral (submódulo de projeto ou os grupos novos) abria o grupo e, no mesmo instante, levava o app para a tela de login. Agora só abre e fecha o grupo.
- **Menu:** a busca do buscador de aplicativos (modo só menu superior) escondia colunas inteiras, mas nunca as telas que não batiam com o texto dentro de uma coluna. Agora filtra tela a tela.

## [5.85.1] — 2026-09-20

### Correções

- **Site público:** a lista de recursos de cada plano mostrava um marcador quadrado ao lado do ícone de confirmação. Ficou só o ícone.

## [5.85.0] — 2026-09-20

### Novidades

- **Site público:** o app ganha um site na raiz do endereço (`/`, `/planos`, `/sobre`, `/contato`, `/blog`, `/cadastro`…), sem login, com as cores e o logo do tema. As páginas são criadas no MadBuilder como tipo "Site público" e aceitam HTML, CSS e JavaScript livres, além dos blocos prontos `<mad-site-*>` (cabeçalho, hero, recursos, planos, depoimentos, logos, perguntas frequentes, chamada, contato, rodapé, texto longo, blog). Sem página inicial publicada, o endereço continua indo para o login.
- **Site público:** a seção de planos lê os planos públicos cadastrados em Administração › Planos, com alternância mensal/anual e o botão certo para cada plano (teste grátis, assinar ou falar com vendas).
- **Site público:** formulário de contato grava os interessados em Administração › Site › Leads (com origem e campanha), avisa o dono por e-mail e tem proteção contra robôs (campo-armadilha, limite por endereço e reCAPTCHA opcional).
- **Site público:** blog com lista e página de post (endereço amigável, resumo, capa, título e descrição para buscadores), editado em Administração › Site › Posts. Rascunho não aparece no site.
- **Cadastro self-service:** com o licenciamento ligado e a opção "Cadastro self-service no site" ativa (Preferências › Site), um visitante escolhe o plano, cria a conta e entra no app já como administrador da própria unidade, com licença e teste grátis do plano. Plano sem teste e com cobrança ativa leva direto ao pagamento.
- **SEO:** as páginas do site trazem título, descrição, endereço canônico, prévia para redes sociais e dados estruturados; o app responde `sitemap.xml` e `robots.txt` automaticamente.

### Atenção

- **Site público:** ações reativas em página de site só rodam se a página as declarar explicitamente; qualquer outra chamada anônima é recusada. Os formulários de contato e cadastro são envios simples, sem sessão.

## [5.84.0] — 2026-09-19

### Novidades

- **Programas:** ao escolher a classe da tela no cadastro de Programa, as ações aparecem sozinhas: as cinco padrão (Visualizar, Incluir, Editar, Excluir, Exportar) mais as ações próprias da tela, como "Aprovar orçamento". O botão "Atualizar da classe" relê a tela; linhas digitadas à mão continuam valendo. A Central de Comando e a atualização do app preenchem os programas que ainda não têm ações.
- **Programas:** no código da tela, `#[MadPermission('Rótulo')]` sobre um método dá nome à ação na tela de Perfis e a mantém na lista mesmo quando o nome do método parece navegação; `#[MadPermission(permission: false)]` tira o método da lista. O rótulo aceita uma chave de tradução, e as ações próprias das telas do sistema (Clonar, Impersonar, Ativar/Desativar…) já aparecem no idioma do usuário.
- **Preferências:** nova opção "Ações sem permissão" na aba Segurança: os botões que a pessoa não pode usar ficam **Desabilitados, com dica** ou **Ocultos**. O valor escolhido aqui vence o que veio da plataforma.

### Correções

- **Permissões:** as ações marcadas no perfil e no grupo (Incluir, Editar, Excluir, Exportar e as próprias da tela) não valiam: um perfil só com Visualizar salvava, excluía e exportava. Agora o app recusa a ação no servidor, e o botão some ou fica cinza com a dica "Sem permissão para excluir" conforme a preferência. Vale para botões de formulário, ações de listagem, de detalhe e o menu Exportar.
- **Permissões:** a exclusão embutida das listagens (`<mad-grid del>`) passava por fora das permissões da tela. Agora responde ao perfil como as outras ações.
- **Preferências:** mudar o identificador de login ou a nova opção de ações em Preferências só valia depois de limpar o cache. Agora vale na hora.

### Atenção

- **Permissões:** perfis e grupos que já tinham caixinhas desmarcadas passam a bloquear de verdade. Quem nunca marcou nada continua com acesso completo. Revise os perfis antes de publicar.
- **Permissões:** nos campos de lista com "adicionar linha", nos anexos e comentários e nos botões de desfecho de aprovação o app já recusa a ação, mas o botão continua aparecendo nesta versão.

## [5.83.3] — 2026-09-19

### Melhorias

- **Minha conta:** trocar de aba (Perfil, Assinatura, Faturas, Pagamento, Dados de cobrança) não recarrega mais o app inteiro; só o conteúdo muda, como nos itens do menu.

## [5.83.2] — 2026-09-19

### Correções

- **Dashboard:** os widgets do app publicado mostravam uma faixa "SQL DEBUG" embaixo de cada indicador e gráfico, com a consulta e os valores reais, mesmo sem depuração ligada. Agora ela só aparece quando você pede.
- **Relatório:** esconder uma coluna pelo seletor desalinhava a faixa de total do grupo — os valores apareciam uma coluna à direita e recarregar não resolvia. As faixas de sub-total já acompanhavam o seletor.
- **Calendário:** no modo de recursos, o nome e a cor da faixa aceitavam só uma coluna. Uma máscara como `{nome} - andar {andar}` deixava a faixa sem nome.
- **Passo a passo:** `<mad-steps>` escrito com abertura e fechamento (`<mad-steps ...></mad-steps>`) e a trilha em `:steps` perdia a trilha inteira e o indicador saía vazio.
- **Linha do tempo:** `<mad-timeline>` com os itens em `:items`, sem `<mad-timeline-item>`, aparecia vazia.
- **App gerado:** um container escrito como tag única — `<mad-detail-form ... />`, `<mad-field-list ... />`, `<mad-timeline ... />`, `<mad-pivot-table ... />`, `<mad-wizard ... />`, `<mad-detail-fields ... />`, `<mad-action-group ... />`, `<mad-db-blocks-form ... />`, `<mad-db-blocks-row ... />`, `<mad-tree-actions ... />`, `<mad-tree-context-menu ... />`, `<mad-menu-item ... />`, `<mad-menu-label ... />` e `<mad-timeline-item ... />` — não era reconhecido: a tela abria em branco, sem mensagem de erro. As duas formas de escrever passam a valer.

## [5.83.1] — 2026-09-18

### Melhorias

- **Cobrança:** a tela Administração › Cobrança foi reorganizada em abas (Provedor, Formas de pagamento, Inadimplência e Regras), com a chave "Cobrança ativada" sempre à vista no topo e o Ambiente junto das credenciais. A régua de inadimplência ganhou uma linha do tempo que acompanha os prazos digitados. Um erro ao salvar agora marca o campo e abre a aba onde ele está.

## [5.83.0] — 2026-09-18

### Novidades

- **Permissões:** uma pessoa pode ter vários perfis na mesma unidade e acumula as permissões de todos. Em Usuários da unidade e em Vincular usuário existente o perfil virou lista de marcação. No cadastro global de Usuários, aba Unidades, os perfis aparecem na linha e são definidos no botão "Perfis e módulos". Rode as migrations pendentes na Central de Comando para aplicar.

### Correções

- **Planos:** a lista "Extras que este plano aceita" abria com o combo vazio, e salvar a tela descartava os extras do plano. Agora o combo lista os extras ativos com o escolhido selecionado.
- **Usuários:** o botão "Módulos liberados" da aba Unidades respondia "Você não tem acesso a esta tela" para o dono do app. Agora abre. Rode as migrations pendentes para aplicar em apps já publicados.
- **Login:** uma unidade em que o vínculo da pessoa foi desativado ainda aparecia na escolha de unidade e só recusava ao confirmar. Agora não aparece.
- **Minha conta:** teste grátis terminado sem plano mostrava "Acesso suspenso por falta de pagamento" com uma lista de faturas vazia, sem caminho de volta. Agora mostra "Seu teste gratuito terminou" e o botão Escolher um plano.
- **Minha conta:** o aviso de atraso dizia "Não conseguimos cobrar" mesmo sem cartão cadastrado, com o nome da forma de pagamento no meio da frase. Sem renovação automática no cartão, o aviso passa a dizer que a fatura venceu e não foi paga.
- **Passo a passo:** `<mad-steps>` com progresso espremia os nomes dos passos para a direita, em qualquer tela. Valia desde a primeira versão do componente.
- **Cobrança:** com o licenciamento desligado, a tela de Cobrança dizia para ligá-lo sem dizer onde. Agora aponta o caminho na plataforma. O formulário de Plano explica onde o preço aparece quando a cobrança está desligada.

## [5.82.3] — 2026-09-18

### Correções

- **Menu:** com só o menu superior ligado (lateral desligada), a faixa superior e o mega-menu mostravam apenas os módulos do projeto — Administração, Documentos e Logs sumiam do app. Agora a faixa também traz o que só existia no menu lateral, sem repetir módulo. Com a lateral ligada nada muda.

## [5.82.2] — 2026-09-18

### Melhorias

- **Assinaturas:** clientes em cortesia ou sem cobrança aparecem em Administração › Assinaturas, no bloco "Contratos sem cobrança", com atalho para o cadastro do cliente.
- **Assinaturas:** plano ou extra que já tem cliente não pode mais ser excluído. A tela orienta a desativar.
- **Botões:** `<mad-btn>` passa a aceitar `title` e `aria-label`. Botões só com ícone ganham nome para leitores de tela.

### Correções

- **Formulários:** `<mad-select-field>` com as opções escritas dentro da tag e sem opção vazia abria sempre na primeira opção, ignorando o valor gravado. Quem salvava a tela gravava a opção errada sem perceber. Agora abre no valor gravado. Campos com `:items` não mudam.
- **Segurança:** com a depuração desligada, uma tela com erro mostrava trecho do código e caminho de arquivos ao usuário. Agora aparece um aviso simples com um código de erro, e o detalhe vai para o log.
- **Permissões:** acesso negado mostrava "403 — Permission denied" em inglês, fora do layout. Agora aparece dentro do app, no idioma do usuário, com botão para voltar ao início.
- **Formulários:** o aviso de campo obrigatório mostrava o nome da coluna ("O campo name"). Agora usa o rótulo da tela, e o título do aviso segue o idioma do usuário.
- **Formulários:** `<mad-checkbox-group-field>` entregava as opções marcadas em formato diferente dos outros campos de múltipla escolha. Agora entrega a lista separada por vírgula.
- **Celular:** `<mad-steps variant="dots">` sobrepunha os nomes dos passos em tela estreita.
- **CEP e CNPJ:** quando a consulta automática não estava disponível, o usuário final via instruções de configuração do servidor. Agora vê "Consulta automática indisponível. Preencha os dados manualmente."
- **Idiomas:** apps em inglês respondiam erro em todas as telas depois da versão 5.82.0.
- **Assinaturas:** renovação com troca de plano agendada cobra o plano novo, renovação de valor zero não gera mais atraso, e salvar uma unidade não altera mais a assinatura do cliente.

### Atenção

- Se o seu app tem telas com `<mad-select-field>` e opções dentro da tag, confira os registros editados por elas: o valor pode ter sido trocado pela primeira opção da lista. No app padrão isso atingia Cotas de IA e a aba IA das Preferências.

## [5.82.1] — 2026-09-18

### Correções

- **Formulários:** um combo dependente com regra de carregamento (`:filters`) passa a respeitar a regra — antes ela era aceita e ignorada, e o combo listava também os registros que a regra excluía. Vale no primeiro carregamento da tela e quando o campo pai muda.


## [5.82.0] — 2026-09-18

### Novidades

- **Minha conta (assinatura):** o administrador da unidade ganha as abas Assinatura, Faturas, Pagamento e Dados de cobrança. Ali ele vê o plano, o uso, a próxima cobrança, troca de plano, compra extras, paga e cancela, sem depender do dono do app.
- **Troca de plano:** o upgrade vale na hora e cobra só a diferença dos dias que faltam no ciclo, com o cálculo mostrado na tela. O downgrade vale no fim do ciclo, sem cobrança nem reembolso, e o cliente escolhe quem continua ativo quando o novo plano tem menos usuários.
- **Extras:** usuários extras por assento, módulos avulsos e pacotes de uso somam à mensalidade sem trocar de plano. O aviso de limite de usuários passa a oferecer "Adicionar usuários extras" e "Mudar de plano" ao administrador da unidade.
- **Pagamento:** Pix, cartão e boleto por Mercado Pago, Banco Inter ou Stripe, ou cobrança manual com instruções do dono. O número do cartão nunca passa pelo app. Renovação automática no cartão só existe com Stripe; nos demais, o cliente recebe a fatura para pagar.
- **Faturas e nota fiscal:** o cliente paga fatura em aberto direto da lista e baixa recibo. O dono envia o arquivo da nota fiscal (PDF ou XML) em cada fatura e o cliente baixa em Faturas.
- **Inadimplência:** nova tentativa, aviso final, suspensão e cancelamento seguem prazos que o dono define. Suspenso, o cliente perde os módulos mas continua entrando para regularizar. Os dados ficam guardados pelo prazo configurado.
- **Teste gratuito e ciclo anual:** cada plano tem preço mensal, preço anual e dias de teste próprios, além de aparecer ou não na vitrine.
- **Painel do dono:** telas novas em Administração: Assinaturas (receita recorrente, trocar plano sem cobrança, cortesia, estender validade, marcar fatura como paga, pagamentos a conciliar), Extras e Cobrança (provedor, formas aceitas, prazos).

### Melhorias

- **Usuários da unidade:** "Ativar/Desativar" passa a valer só para aquela unidade. Um contador desativado em um cliente continua ativo nos outros.

### Atenção

- Nada muda enquanto a cobrança estiver desligada (padrão). Ela exige o licenciamento ligado e é ativada pelo dono em Administração › Cobrança. Republique o projeto para receber as telas novas.
- As credenciais do provedor de pagamento são digitadas só no app, em Cobrança, e ficam cifradas. Cadastre no provedor a URL de aviso de pagamento mostrada nessa tela.


## [5.81.1] — 2026-09-18

### Correções

- **Formulários:** a tela de consulta (só leitura) com listagens de detalhe abria com erro em vez de mostrar o registro. Agora as listagens do detalhe carregam normalmente.

## [5.81.0] — 2026-09-18

### Novidades

- **Login:** o app pode identificar o usuário pelo apelido (login), pelo e-mail ou pelos dois — a tela de login ajusta rótulo, ícone e teclado sozinha. A escolha vem das Propriedades do projeto no MadBuilder (Contas → Entrar com). O apelido continua existindo no cadastro.
- **Unidades e empresas (licenciamento):** com o licenciamento ligado no painel Multi-tenancy & Banco, cada unidade — ou cada empresa com várias unidades — vira um cliente com dados do contratante (razão social, CNPJ, contato), plano, módulos contratados, módulos avulsos, data de validade e limite de usuários. Unidade sem licença própria herda a da empresa.
- **Planos e Módulos:** telas novas em Administração. Os módulos do projeto aparecem sozinhos (vêm da geração); um plano é um pacote de módulos com limite de usuários.
- **Perfil por unidade:** o mesmo usuário pode ter perfil e módulos diferentes em cada unidade que acessa. Menu e permissões mudam ao trocar de unidade, sem sair do app.
- **Usuários da unidade:** o administrador da unidade ganha uma tela para criar, editar, desativar e vincular usuários da própria unidade, escolher perfil e módulos liberados e enviar o link para a pessoa definir a senha. O limite de usuários do contrato é respeitado; a senha nunca passa pelo administrador.
- **Perfis:** o dono do app marca quais perfis ficam disponíveis ao administrador da unidade; perfis internos da equipe nunca aparecem para o cliente nem podem ser atribuídos por ele.
- **Licença vencida:** as telas dos módulos vencidos somem, a barra superior mostra um aviso e a lista de unidades no login sinaliza a situação. O acesso não é bloqueado, para o cliente conseguir falar com o suporte e renovar.

### Atenção

- Nada muda enquanto o licenciamento estiver desligado (padrão). Apps já publicados recebem as tabelas novas pelas migrations do framework em Central de Comando → Migrations → Migrar pendentes. As telas, rotas e traduções novas chegam ao republicar o projeto.
- Perfis já existentes começam indisponíveis ao administrador da unidade; marque no cadastro de Perfis quais ele pode atribuir.


## [5.80.0] — 2026-09-17

### Novidades

- **Relatório:** campos próprios do PDF (ex.: `{CNPJ}`) agora podem ser valores fixos definidos no MadBuilder, sem código — chegam ao app pelo mesmo arquivo do cabeçalho padrão do projeto. A classe global de valores calculados passa a ser `App\Helpers\PdfExportPlaceholders` (o MadBuilder cria o esqueleto dela com um clique); `App\Support\PdfExportPlaceholders` continua aceita. Ordem: página, depois classe, depois valores fixos.

## [5.79.0] — 2026-09-17

### Novidades

- **Relatório:** o cabeçalho e o rodapé do PDF exportado ganham `{UNIT_NAME}` (unidade ativa), `{USER_NAME}` (quem exportou) e `{TENANT_NAME}` (empresa). Sem unidade ou empresa, o campo sai em branco.
- **Relatório:** a tela pode definir campos próprios para o PDF (ex.: `{CNPJ}`) com o método `exportPdfPlaceholders()`; um valor comum a todas as telas vale pela classe `App\Support\PdfExportPlaceholders`. Os campos são texto puro; `{LOGO}` e a paginação não podem ser sobrescritos.

## [5.78.0] — 2026-09-16

### Novidades

- **Login:** o tamanho do logo da tela de login passa a ser configurável pelo tema (altura e largura máxima). Antes o logo tinha altura fixa e marcas com margem ficavam pequenas demais. Sem configuração, o tamanho continua o mesmo.
- **Menu:** o logo acima do menu também passa a ter altura e largura máxima configuráveis pelo tema, até o limite da barra superior. Sem configuração, o tamanho continua o mesmo.

## [5.77.0] — 2026-09-16

### Novidades

- **Listagem (grid):** `require-filter` obriga o usuário a preencher ao menos um filtro antes de buscar. A tela abre vazia, e Aplicar, Atualizar, ordenar ou trocar a quantidade por página sem nenhum filtro mostram um aviso em vez de carregar a tabela inteira.
- **Listagem (grid):** `require-filter-fields="nome,cpf"` define quais filtros liberam a busca; basta preencher um deles.
- **Listagem (grid):** `load-hint="..."` troca o texto da listagem vazia, e `no-load-button` oculta o botão Carregar registros. Com `require-filter` o botão já não aparece.

### Melhorias

- **Listagem (grid):** o texto da listagem que abre vazia não manda mais clicar em Buscar, que não existe em telas com o botão Aplicar, e deixou de aparecer repetido no rodapé.

## [5.76.1] — 2026-09-14

### Melhorias

- **Relatório:** quebras ganham contraste por nível, subtotais identificam seu grupo e o total geral recebe destaque. Atualize o framework do app para aplicar.

## [5.76.0] — 2026-09-12

### Novidades

- **Listagem (grid):** a quebra pode ser por dia, semana, mês ou ano de um campo de data — `group-by="data_venda|day"` (também `|week`, `|month`, `|year`). Antes, numa coluna com data e hora, cada horário virava um grupo.
- **Listagem (grid):** sem `group-mask`, o título da quebra por data já sai pronto no idioma do projeto: `09/03/2026` no dia, `Março/2026` no mês, `2026` no ano e `Semana 11/2026` na semana.
- **Listagem (grid):** a granularidade combina com campo de outra tabela (`group-by="venda->data|day"`) e com quebra em vários níveis (`group-by="data_venda|day,vendedor_id"`). Vale também em `<mad-data-table>`, nos sub-totais, no saldo acumulado que zera na quebra, no PDF, no Excel e no CSV.

### Correções

- **Listagem (grid):** quando o campo da quebra estava vazio (uma data em branco, por exemplo), o título do grupo saía com o texto do próprio código (`{data|date}`) na tela, no PDF e no CSV. Agora o título fica em branco; só um campo que não existe continua aparecendo como código, para o erro não passar despercebido.
- **Listagem (grid):** em `<mad-data-table>`, uma máscara de quebra com formatação no campo (`group-mask="{data|date}"`) era cortada ao meio e o grupo saía com o texto quebrado.

## [5.75.0] — 2026-09-12

### Novidades

- **Listagem (grid):** a quebra pode ser por um campo de outra tabela — `group-by="rubrica->codigo"`, com quantos níveis forem necessários (`cidade->estado->nome`). Os grupos, os sub-totais, o saldo acumulado que zera na quebra, o PDF, o Excel e o CSV seguem o mesmo agrupamento.
- **Listagem (grid):** `order-by` e a ordenação padrão aceitam campo de outra tabela (`order-by="cidade->nome asc"`), inclusive misturado com campos da própria tabela.

### Correções

- **Listagem (grid):** uma quebra com o nome vindo de outra tabela mostrava o texto do próprio código na tela (`Vendedor: {vendedor->nome}`) em vez do nome. Republique o projeto para aplicar.
- **Listagem (grid):** agrupar por um campo de outra tabela juntava todos os registros num único grupo em branco, sem avisar. Quando o caminho não existe, o agrupamento é ignorado e o aviso aparece no log em vez de a tela mentir.
- **Listagem (grid):** uma coluna escrita como `cidade->nome` (sem chaves) aparecia vazia.
- **Listagem (grid):** ordenar por campo de outra tabela na abertura da tela derrubava a listagem com erro. Quando a ordenação não é possível (tabela em outro banco), a tela abre sem ela e registra o motivo.
- **Listagem (grid):** telas com campo de outra tabela faziam uma consulta por registro exibido; agora buscam tudo de uma vez.

## [5.74.0] — 2026-09-12

### Novidades

- **Listagem (grid):** uma coluna pode exibir saldo acumulado linha a linha (`running`), zerando a cada quebra (`running-reset="group"`) e partindo de um saldo inicial (`running-start`). O rodapé dessa coluna mostra o saldo final, não a soma dos saldos.
- **Listagem (grid):** `row-detail` acrescenta uma segunda linha descritiva abaixo de cada registro, com a mesma sintaxe de máscara da quebra. Aparece na tela e no PDF; Excel e CSV continuam com uma linha por registro.
- **Listagem (grid):** `group-band="cells"` alinha os totais da quebra sob as colunas correspondentes, e `group-total-label` troca o rótulo do sub-total (aceita `{group}`).
- **Relatório:** o cabeçalho e o rodapé do PDF aceitam `{PERIOD}`, `{FILTERS}` e `{SUBTITLE}` — o período e os filtros escolhidos saem impressos. Republique o projeto para aplicar.
- **Relatório:** `export-title`, `export-subtitle` e `export-filename` podem ser declarados direto na listagem e aceitam `{PERIOD}` no texto.
- **Listagem (grid):** as máscaras de quebra e de linha descritiva aceitam formatação no campo (`{valor|money}`).
- **Relatório:** as cores das faixas de quebra e de total do PDF podem ser trocadas por projeto.

### Correções

- **Relatório:** o sub-total da quebra deixa de esconder o valor da primeira coluna no PDF, e o rótulo deixa de sobrescrever esse valor no Excel e no CSV.
- **Listagem (grid):** `total-mask` passa a funcionar na coluna, e o total da quebra usa a mesma formatação do total geral.
- **Listagem (grid):** o título de exportação declarado na tela deixa de se perder ao gerar o PDF.
- **Listagem (grid):** os filtros ativos voltam a mostrar o valor escolhido nos atalhos e na barra de filtros; apareciam em branco.

## [5.73.0] — 2026-09-11

### Correções

- **Listagens:** os botões de ação da linha (Excluir, ações com confirmação, ações do menu e dos cards) não respondiam em tabelas cuja chave primária é UUID ou outro texto; só chave numérica funcionava. Vale também para a linha reaparecida depois de uma atualização parcial. Editar em linha já funcionava e continua igual.
- **Formulários:** `display` com relação, como `{nome} | {estado->sigla}`, mostrava a parte da relação em branco ("Porto Alegre | ") em `<mad-dbcombo-field>` e nos demais campos de banco (dbselect, dbradio, checkbox-group, select-check, sort-list, checklist), inclusive no combo dependente, no recarregamento do cadastro rápido e na busca de `<mad-dbunique-search-field>` / `<mad-dbmulti-search-field>`. A relação pode estar declarada no model ou ser inferida pela coluna `estado_id`; chain de dois níveis (`{estado->pais->nome}`) também funciona. A busca digitada continua filtrando só pelas colunas da própria tabela.
- **Listagens (edição em linha):** confirmar uma célula editada em linha também falhava em silêncio com chave UUID.
- **Calendário:** arrastar ou redimensionar um evento de tabela com chave UUID não gravava a alteração; passa a gravar.
- **Kanban:** em quadros de tabela com chave UUID ou outro texto, clicar num card abria sempre o registro errado, arrastar entre colunas não gravava e as ações do card não faziam nada. Agora o card carrega a chave real.
- **Fluxos de aprovação e Conciliação:** registros de tabela com chave UUID ou outro texto não podiam ser enviados para aprovação nem conciliados — o vínculo com o registro se perdia na gravação e o mapa do fluxo nunca aparecia. Republique o projeto para aplicar (a alteração inclui uma atualização do banco).

### Atenção

- **Kanban:** quadros de tabela com chave UUID ou texto: se a sua página sobrescreve `afterCardMove`, troque o parâmetro `int $cardId` por `int|string $cardId` — com `int` o gancho é ignorado nesses quadros (páginas com chave numérica não mudam nada).

## [5.72.0] — 2026-09-11

### Novidades

- **Listagem (grid):** nova opção `no-auto-load` no `<mad-grid>` — a listagem abre vazia e só consulta o banco depois do primeiro Buscar, filtro ou ordenação (o "Carregar registros ao abrir = Não" do MadBuilder 4). Indicada para tabelas com milhares de registros. A tela mostra a dica e um botão "Carregar registros"; Limpar filtros volta ao estado vazio e a exportação pede para carregar antes.

## [5.71.0] — 2026-09-11

### Melhorias

- **Usuário logado:** `auth()->user()`, `auth()->id()`, `Auth::check()` e `request()->user()` passam a enxergar o usuário logado no app (o mesmo de `session('userid')`) em toda tela e ação reativa. Código que gravava `created_by = auth()->id()` deixava o campo vazio em silêncio; agora grava o usuário certo. `session('userid')` continua valendo.

## [5.70.1] — 2026-09-11

### Correções

- **Detalhe:** o total no rodapé da listagem (`<mad-col total="sum">`) saía sem a formatação da coluna — `9.990` no total enquanto a linha mostrava `9.990,00`. Agora o total usa o mesmo formato da célula (`transform`, `num=`, `money=`), e `total="avg"`, `"min"` e `"max"` passam a funcionar no detalhe, como na listagem principal.

## [5.70.0] — 2026-09-11

### Correções

- **Campos:** data preenchida pela plataforma (ao reabrir uma linha do detalhe para editar, por `<fill>`, pela busca de registro ou por `$this->form->set()`) aparecia embaralhada — `11/09/2026` virava `20/26/0911` — e essa data inválida era a que o próximo salvamento gravava. Vale para data e para data/hora.

## [5.69.3] — 2026-09-11

### Correções

- **Busca (dbseek):** campo auxiliar de dinheiro ou numérico preenchido ao escolher um registro mostrava o valor antigo na tela, e o preenchimento acertava o campo de mesmo nome do formulário principal quando a busca estava dentro de um sub-form de detalhe.
- **Campos:** busca de CEP e de CNPJ que preenche campo de dinheiro ou numérico (capital social, número) gravava o valor sem atualizar o que aparece na tela.

## [5.69.2] — 2026-09-11

### Correções

- **Combo:** `<fill>` não preenchia campo de dinheiro nem numérico: o valor chegava do servidor, entrava no dado enviado, mas a tela continuava mostrando "0,00". Agora o campo mostra o valor preenchido.
- **Combo:** `<fill>` disparado dentro do sub-form de um `<mad-detail-form>` escrevia no campo de mesmo nome do formulário principal. Agora preenche o campo da linha em edição.
- **Listagem (grid):** o mesmo acerto vale para campo de dinheiro preenchido por `on-change` de coluna do field-list, que antes ignorava as casas decimais configuradas.

## [5.69.1] — 2026-09-11

### Correções

- **Assets:** correção de framework não chegava ao navegador: o JS ficava preso no cache e a tela seguia com o bug já resolvido, mesmo depois de republicar. Agora cada arquivo do MAD carrega a versão do framework no endereço e o navegador busca a cópia nova sozinho. Se você ainda vê comportamento antigo em um app publicado antes desta versão, force um recarregamento (Ctrl+Shift+R / Cmd+Shift+R) uma última vez.

## [5.69.0] — 2026-09-11

### Novidades

- **Detalhe:** `mad:change` agora funciona nos campos do sub-form. O método recebe o valor do campo, lê os outros campos da linha em edição com `$this->form->get('campo')` e devolve o resultado com `$this->form->set('campo', valor)` — útil para calcular total a partir de quantidade × preço.

### Correções

- **Detalhe:** campo com `mad:change` dentro do sub-form em `mode="drawer"` ou `mode="modal"` respondia "Método não permitido". O evento chegava na tela errada; agora vai para o formulário dono do detalhe.
- **Campos:** valor definido por `$this->form->set()` em campo numérico ou de dinheiro atualizava o dado enviado, mas a tela continuava mostrando o número antigo.
- **Campos:** `mad:change` em campo numérico ou de dinheiro entregava o valor ANTERIOR ao método. Agora entrega o valor recém-digitado, cru (com ponto decimal) — se o seu método desfazia a máscara pt-BR do valor recebido, remova essa conversão e use `(float)`.
- **Detalhe:** ao reabrir uma linha para editar, campo com `:decimals="3"` (ou diferente de 2) voltava arredondado para duas casas.

## [5.68.2] — 2026-09-11

### Correções

- **Detalhe:** em `mode="drawer"`, a coluna de outra tabela continuava vazia na linha adicionada e ficava em branco ao editar a linha. Agora o servidor resolve a coluna também no drawer, e a edição nunca apaga o valor.
- **Detalhe:** `before-add` com `mode="drawer"` descartava a linha sem aviso. A linha passa a entrar na listagem.
- **Detalhe e Listagem (grid):** coluna `{produto->categoria_produto->nome}` vinha vazia quando a relação no model tinha outro nome (`categoriaProduto`) ou não existia. Agora a coluna resolve pelo campo `categoria_produto_id`, buscando o registro no banco.

## [5.68.1] — 2026-09-11

### Correções

- **PDV:** o catálogo de produtos não mostrava o estoque no card, mesmo com a coluna de saldo configurada no `<mad-pdv>`. O saldo passa a aparecer junto do preço, destacado quando está zerado. Republique o projeto para aplicar.

## [5.68.0] — 2026-09-11

### Novidades

- **Detalhe:** `<mad-col transform="...">` no `<mad-detail-form>` passa a valer, igual à listagem principal: Real (`money`), CPF, CNPJ, CEP, telefone, datas, percentual, booleano e transformadores próprios. Antes a coluna mostrava o valor cru.
- **Detalhe:** colunas `num=` e `html` no detalhe também eram exibidas cruas; agora formatam.

### Correções

- **Detalhe:** coluna de outra tabela (`{produto->nome}`) ficava vazia na linha adicionada pelo botão "Adicionar" e mostrava o nome antigo ao trocar o produto na edição. Agora o servidor resolve a coluna logo após adicionar ou editar a linha.
- **Detalhe:** coluna calculada com `evaluate` era mostrada na tela mas não gravada no banco quando a linha vinha do servidor.
- **Detalhe:** coluna de relacionamento que não resolve (relação não declarada no model) agora registra o motivo no log em vez de ficar vazia em silêncio. Formatador com nome errado também.

### Atenção

- **Detalhe:** transformador próprio (`Classe::metodo`) formata as linhas carregadas do banco; a linha recém-adicionada aparece formatada depois de salvar. Formatadores padrão (`money`, `cpf`, `date`...) formatam na hora.

## [5.67.4] — 2026-09-10

### Correções

- **PDV:** o cupom não mostrava as colunas extras configuradas com `<mad-pdv-column>` (o navegador ainda registrava um aviso no console a cada venda finalizada).

## [5.67.3] — 2026-09-10

### Correções

- **PDV:** produtos com o campo de "ativo" gravado como `T`, `true` ou `S` (em vez de 1) não apareciam na busca nem no bip, só o aviso "Produto não encontrado". A frente de caixa passa a reconhecer esses valores como ativo; um `active-value` próprio continua sendo comparado exatamente.

## [5.67.2] — 2026-09-09

### Correções

- **App gerado:** aplicação sem chave de segurança definida (`APP_KEY`) e com ambiente fora dos nomes `production`/`staging` — vazio, `prod`, `homolog`, `qa` ou até `PRODUCTION` em maiúsculas — subia usando uma chave pública embutida no framework, sem avisar. Agora só ambiente de desenvolvimento reconhecido aceita essa chave; qualquer outro recusa iniciar.

### Atenção

- **App gerado:** se algum ambiente seu roda sem `APP_KEY` definida, ele passa a recusar operar com uma mensagem explicando o que falta. Rode `php artisan key:generate` (e `php artisan config:cache`, se você usa cache de configuração). Depois de definir a chave, telas abertas no navegador precisam ser recarregadas uma vez.

## [5.67.1] — 2026-09-09

### Correções

- **Listagem (grid):** a edição inline gravava qualquer coluna da tabela, e não só as marcadas como editáveis — bastava uma requisição forjada para alterar campos que a listagem nunca mostrou. Agora a gravação só aceita coluna declarada editável. Atualize o framework.

### Atenção

- **Listagem (grid):** se alguma tela gravava por edição inline uma coluna que não está marcada como editável na listagem, essa gravação passa a ser recusada (a célula volta ao valor do banco). Marque a coluna como editável para liberar.

## [5.67.0] — 2026-09-09

### Novidades

- **Campos:** `empty-as` nos campos numéricos (`<mad-number-field>`, `<mad-money-field>`, `<mad-numeric-field>`, `<mad-spinner-field>`, `<mad-range-field>`) escolhe o que gravar quando o campo fica em branco: `null` (padrão), `zero` ou `empty`.

### Correções

- **Campos:** salvar um formulário com campo numérico em branco falhava com "Incorrect integer value" no MySQL/MariaDB e gravava lixo silencioso no SQLite (a linha aparecia em filtros de "maior que"). O campo vazio agora grava vazio (NULL) na coluna.

### Atenção

- **Campos:** se você aponta um campo numérico para uma coluna de TEXTO que não aceita vazio (`NOT NULL`), o salvamento em branco passa a ser recusado pelo banco — antes gravava texto vazio. Use `empty-as="empty"` nesse campo para manter o comportamento antigo.

## [5.66.1] — 2026-09-09

### Correções

- **Campos:** salvar um formulário com campo de data, data/hora ou hora em branco falhava com "Incorrect date value" no MySQL/MariaDB. O campo vazio agora grava vazio (NULL) na coluna.

## [5.66.0] — 2026-09-09

### Novidades

- **PDV:** ao lado do campo de código de barras há um botão que abre o catálogo de produtos, para escolher pelo nome quando não há leitor à mão.

### Melhorias

- **PDV:** o catálogo de produtos passa a vir ligado e a responder ao `F2` que a legenda do rodapé sempre anunciou como "Produto". Para desligar, use `product-picker="false"`.

### Correções

- **PDV:** quando o navegador bloqueia a impressão (pré-visualização do MadBuilder, por exemplo), o botão Imprimir não fazia nada. Agora o cupom aparece na tela com um aviso.

## [5.65.1] — 2026-09-09

### Correções

- **Tema:** no tema escuro, a Frente de Caixa (`<mad-pdv>`) aparecia com todos os cartões brancos e o texto claro por cima, praticamente ilegível. Cartões, carrinho, formas de pagamento e botões agora seguem o tema. Atualize o framework.
- **Tema:** no tema escuro, os avisos (toasts), o calendário do `<mad-period-monthyear>` e os nós do `<mad-wf-map>` ficavam com fundo branco. Todos passam a acompanhar o tema.
- **Tema:** textos secundários de gráficos, tabela dinâmica, upload de vários arquivos e das listas vazias de grid e detalhe não recebiam cor nenhuma e saíam com a cor do bloco em volta. Agora usam o cinza de apoio do tema.
- **Campos:** `<mad-badge variant="error">` saía sem cor. Agora usa o vermelho de erro do tema.

## [5.65.0] — 2026-09-08

### Novidades

- **Modelo de dados:** o log de mudança de objeto voltou a ser automático. Adicione `use HasMadChangeLog` no model e toda criação, alteração e exclusão grava valor antigo → novo, coluna a coluna, na tela Logs → Alterações (equivale ao `TRACKCHANGES` do Adianti). Senha, token e campos ocultos do model entram mascarados (`***`); `MAD_CHANGE_LOG=false` desliga tudo. Atualize o framework.
- **Listagem (grid):** a tela Logs → Alterações ganhou o modo **Linha do tempo**: cada gravação vira um card com quem/quando/tela e um diff coluna a coluna (valor antigo riscado → valor novo). O mesmo visual está disponível como `<mad-change-timeline table="…" :pk="…">` para uma aba "Histórico" em qualquer formulário.

### Correções

- **Listagem (grid):** nas telas Logs → Alterações e Logs → SQL, o botão de trace abria o painel lateral vazio. Agora o trace aparece. Atualize o framework.

## [5.64.0] — 2026-09-08

### Novidades

- **Banco de dados:** apps em MySQL/MariaDB aceitam conexão com certificado SSL/TLS (CA do servidor e, opcionalmente, certificado e chave do cliente), como exigem bancos gerenciados da DigitalOcean e similares. Configure no painel Multi-tenancy & Banco do MadBuilder ou pelas chaves `DB_MAD_SSL_CA`, `DB_MAD_SSL_CERT`, `DB_MAD_SSL_KEY` e `DB_MAD_SSL_VERIFY` no `.env`. Atualize o framework.

## [5.63.1] — 2026-09-08

### Correções

- **Formulários:** num detalhe do formulário (a lista com "Adicionar"), as linhas recém-adicionadas apareciam em branco na listagem — os valores só reapareciam ao clicar em Editar ou depois de salvar e reabrir a tela. Republique o projeto para aplicar.

## [5.63.0] — 2026-09-07

### Novidades

- **Layout:** novo componente `<mad-image>` para exibir imagens do projeto (enviadas pelo editor em Novo arquivo → Imagem) com tamanho, ajuste, cantos arredondados e alinhamento. Atualize o framework.

## [5.62.1] — 2026-09-07

### Correções

- **Relatórios:** a exportação para PDF, CSV e Excel agora respeita as colunas selecionadas na listagem, incluindo os totais gerais e por grupo. Atualize o framework.

## [5.62.0] — 2026-09-07

### Novidades

- **Dashboards:** consultas por intervalo aceitam datas brasileiras ou ISO, respeitam as regras base do próprio dashboard e os filtros de empresa e unidade, e permitem comparar períodos de igual duração. Atualize o framework.

### Melhorias

- **Campos:** o seletor de período permite aplicar ou limpar as duas datas de uma vez, validando ordem e limites antes da alteração. Atualize o framework.

## [5.61.0] — 2026-09-07

### Novidades

- **Dashboards:** `<mad-kpi-card>` passa a calcular o valor e a variação em relação ao período anterior sozinho. Informe `model`, `field` e `total` (ou `:current-query` e `:compare-query`) e a seta com o percentual aparece automaticamente, junto com `format` para moeda, percentual ou número.
- **Dashboards:** novo modo de comparação `compare-mode="mtd"`, que compara do dia 1 até hoje com o mesmo trecho do mês passado, alinhado por dia. Exige que o filtro de período use um campo de data.

### Melhorias

- **Dashboards:** dashboards com filtro de período por data abrem já com o mês atual (dia 1 até hoje) selecionado, em vez de vazios somando a tabela inteira. O botão Limpar volta para esse mesmo padrão.
- **Dashboards:** com a depuração ligada, o `<mad-kpi-card>` mostra também o SQL do período de comparação, não só o do período atual.

### Correções

- **Dashboards:** o `KPI Compare` montado pelo editor visual quebrava a página do dashboard com erro de método inexistente ao calcular o período anterior. Agora renderiza normalmente. Atualize o framework.

## [5.60.0] — 2026-09-06

### Melhorias

- **Upload:** apps passam a receber a configuração de armazenamento das propriedades do projeto, inclusive ao sincronizar o Teste Online. Republique apps existentes para aplicar.

## [5.59.3] — 2026-09-05

### Correções

- **Formulário:** registros salvos com empresa ou unidade em branco podiam desaparecer das listagens. O cadastro agora mantém o vínculo correto ao criar e editar. Atualize o framework.

## [5.59.2] — 2026-09-05

### Correções

- **Responsividade:** cabeçalho, menus, listas, paginação e formulários se adaptam a celulares; o botão de ações do cabeçalho também passa a abrir e fechar corretamente.

## [5.59.1] — 2026-09-05

### Correções

- **Tema:** o seletor deixa de acrescentar temas não configurados no projeto e fica oculto quando há somente uma opção. Republique o projeto para aplicar.
- **Autenticação:** a tela de login passa a carregar as cores e personalizações do tema padrão do projeto. Republique o projeto para aplicar.

## [5.59.0] — 2026-09-04

### Novidades

- **Layout:** abas internas: cada item de menu abre uma aba (como no Beto v4) e o que a tela abre por dentro (Novo, Editar, voltar) fica na mesma aba. Trocar de aba atualiza a URL, o menu e a trilha de navegação; as abas voltam no F5. Ative com `MAD_USE_TABS=1` (`MAD_STORE_TABS=0` desliga a restauração). Desligado, nada muda.

### Melhorias

- **Layout:** aba que abre uma tela indisponível (rota morta, sem permissão) fecha sozinha com aviso, em vez de ficar pendurada como "404".

## [5.58.0] — 2026-09-04

### Novidades

- **Autenticação:** nova tela de login em tela dividida (variante 6): marca, destaques do sistema e selo de acesso à esquerda; formulário à direita com "Lembrar de mim", "Redefinir senha" e aviso de privacidade. Ative com `preset.login: "6"` no tema ou `MAD_LOGIN_VARIANT=6`. Textos e destaques vêm de `login.headline`, `login.features`, `login.badge`, `login.terms`, `login.developedBy` e `login.contactAdmin` no tema.
- **Tema:** novo tema "ERP" para o sistema: trilha de módulos na cor da marca com rótulos, painel de módulo recolhível e barra superior enxuta com trilha de navegação e busca. Ative com `MAD_THEME=erp` ou pelo seletor "Tema" no cabeçalho, que agora lista sempre "Padrão" e "ERP".
- **Tema:** cor da marca configurável por `MAD_BRAND_COLOR` e `MAD_ACCENT_COLOR` (ou `layout.colors.primary`/`accent` no tema). Botões, destaques, badges, trilha lateral e o degradê do login seguem a cor; o contraste dos ícones da trilha é calculado automaticamente.
- **Layout:** barra fina de progresso no topo durante a troca de tela, em todos os temas.

## [5.57.1] — 2026-09-04

### Correções

- **Campos:** o tamanho máximo e a proporção de recorte configurados nos campos de imagem e de assinatura passam a valer no app; antes ficavam sempre no padrão.
- **Campos:** propriedades escritas com hífen (`display-mask`, `name-start`, `date-format`, `active-icon`) eram ignoradas nos campos de data, de período, no editor de texto, na linha do tempo e na árvore. Agora valem.

## [5.57.0] — 2026-09-04

### Correções

- **Listagens:** numa tabela cuja chave primária não se chama `id`, os botões Editar e Excluir da linha agiam no registro errado — ou em nenhum. Agora seguem a chave real da tabela.
- **Formulários:** em tabelas com chave primária de nome próprio, o formulário abria em branco ao clicar em Editar na listagem.
- **Formulários:** os itens de uma lista de detalhe eram apagados e recriados a cada gravação quando a tabela filha tinha chave primária de nome próprio, perdendo colunas que não apareciam na lista. Agora as linhas existentes são atualizadas no lugar.

### Atenção

- **Formulários:** ao gravar uma lista de detalhe, uma linha só é atualizada se pertencer ao registro aberto. Linha de outro registro passa a ser tratada como nova, nunca reaproveitada.

## [5.56.2] — 2026-09-03

### Correções

- **Formulário:** erro de validação em campo que não está na tela agora aparece no aviso ao salvar. Antes o formulário só dizia "Corrija os erros antes de continuar", sem indicar o campo.

## [5.56.1] — 2026-09-02

### Correções

- **Listagem (grid):** em telas com mais de um grid (mestre/detalhe, abas), a linha inserida ou removida por uma ação aparecia no grid errado.
- **Listagem (grid):** a linha atualizada por uma ação não some mais nem fica em branco quando o registro não é encontrado.

## [5.56.0] — 2026-09-02

### Novidades

- **Documento (PDF):** páginas de documento ganham URL própria (`/app/docs/{slug}/{id}`), com o mesmo controle de acesso das demais telas. Antes, abrir o documento pelo `<mad-nav>` dava erro 404.

## [5.55.0] — 2026-09-01

### Correções

- **Documentação:** a busca do portal (Cmd+K) não abria. Agora funciona, ignora acentos, realça o termo encontrado e aceita navegação por setas e Enter.

## [5.54.5] — 2026-09-01

### Correções

- **Reatividade (MadWire):** botão com `mad:loading` mostrava o texto normal e o de carregamento ao mesmo tempo na primeira exibição após uma navegação.

## [5.54.4] — 2026-08-31

### Correções

- **Campos:** o modo "desenhar" do `<mad-signature-field>` não fazia nada ao tentar assinar; só o modo "digitar" funcionava.
- **Notificações:** telas com o componente legado `<mad-toast />` deixavam de exibir qualquer aviso (toast) dali em diante. O componente não é mais necessário e pode ser removido.

## [5.54.3] — 2026-08-31

### Correções

- **Dashboard:** um `@php(...)` de uma linha logo antes de `<mad-dash-filters>` quebrava a página inteira, sem nenhum erro apontando a causa.

## [5.54.2] — 2026-08-31

### Correções

- **Dashboard:** gráfico agrupado por um campo criado com apelido (alias) em JOIN falhava com erro de coluna inexistente.

## [5.54.1] — 2026-08-31

### Correções

- **Listagem (grid):** `=>` ou `->` dentro do valor de um atributo cortava a tag sem aviso: `:items="[1 => 'A']"` perdia os itens e `group-mask="{categoria->nome}"` mostrava "1" em todos os grupos.
- **Detalhe (mestre/detalhe):** o aviso de diagnóstico do `<mad-field-list>` derrubava a página inteira em vez de ajudar.

## [5.54.0] — 2026-08-26

### Melhorias

- **Banco de dados:** abrir o explorador de banco de um app com muitas tabelas grandes não faz mais contagem completa de linhas em cada tabela; a contagem passa a ser estimada e a leitura do schema fica muito mais rápida.

## [app-template] — 2026-08-25

### Correções

- **App gerado:** a aba do navegador, o cabeçalho das páginas públicas, a tela de login e os e-mails de cadastro e de redefinição de senha mostravam "Mini CRM" ou um texto de teste em vez do nome do app.
- **App gerado:** o manifesto PWA apontava para um ícone inexistente; agora usa o nome do app e o ícone enviado no projeto.

## [app-template] — 2026-08-24

### Novidades

- **App gerado:** a "Imagem de fundo do layout" definida nas Propriedades do Projeto passa a aparecer no app publicado.

### Correções

- **App gerado:** as personalizações da tela de login (imagens, textos, cores e variante) e os temas criados no MadBuilder eram ignorados pelo app publicado.
- **App gerado:** uma falha ao carregar os dados de referência deixava o app sem a senha do admin e com o menu vazio, sem erro visível; a conta admin e as permissões agora são garantidas.
- **App gerado:** usuário cuja página inicial apontava para uma tela inexistente recebia erro 404 logo após o login; agora abre uma página vazia.
- **App gerado:** trocar o favicon ou a logo passa a refletir na tela sem precisar limpar o cache do navegador.

## [5.53.1] — 2026-08-20

### Correções

- **Menu:** o menu superior não aparecia mesmo quando ligado. Agora é exibido como uma faixa abaixo do cabeçalho, no estilo do tema, com submenus e rolagem quando a lista transborda.
- **Listagem (grid):** campo de texto do filtro lateral não filtrava nada: aplicar o filtro devolvia a lista inteira.

## [5.53.0] — 2026-08-20

### Melhorias

- **Listagem (grid):** o filtro lateral em campo de texto passa a buscar por trecho (digitar "vend" encontra "Vendedor"). O operador pode ser definido por campo com `filter-op` (`=`, `!=`, `like`, `starts`, `ends`, `>`, `>=`, `<`, `<=`).

### Correções

- **Upload:** campo de imagem obrigatório reprovava o salvamento mesmo com o arquivo anexado ("O campo Foto é obrigatório"), e regras de tamanho reprovavam registros que nem tocaram no arquivo.
- **Menu:** a posição do menu (lateral, superior ou ambos) escolhida no MadBuilder não chegava ao app publicado: a barra superior aparecia sempre vazia e a lateral não podia ser desligada.
- **Listagem (grid):** coluna com o arquivo embutido (base64) estourava o layout da listagem; agora mostra a miniatura, e no PDF exportado sai o nome do arquivo.

## [5.52.0] — 2026-08-14

### Correções

- **Campos:** a prop `value` era ignorada em vários campos (número, texto, senha, hora, dinheiro, OTP, select, rádio e switch, entre outros): o campo aparecia vazio. O campo numérico deixa de mostrar "0" como placeholder.
- **Campos:** `<mad-input-field>`, `<mad-password-field>`, `<mad-cep-field>`, `<mad-cnpj-field>` e `<mad-dbentry-field>` descartavam atributos extras passados em `attrs` (`mad:change`, `@change`, `data-*`); agora são preservados.

### Atenção

- **Campos:** a precedência entre o valor do registro e a prop `value` foi unificada em todos os campos: vale o registro quando preenchido, senão `value`, senão vazio. Se você usava `value` para sobrepor o dado do registro em tela de edição, ajuste a tela.

## [5.51.1] — 2026-08-11

### Correções

- **Campos:** o botão "Câmera" do `<mad-image-field>` aparecia sem estilo, com o visual padrão do navegador.

## [5.51.0] — 2026-08-09

### Novidades

- **Listagem (grid):** coluna de arquivo ou imagem ganha os transformadores `file-avatar`, `file-thumb`, `file-link` e `file-gallery`; as miniaturas abrem numa galeria navegável com botão Baixar. No PDF sai o nome do arquivo.
- **Campos:** `<mad-checkbox-group-field>` e `<mad-dbcheckbox-group-field>` aceitam `as="button"`, `size="sm|lg"` e `break-items="N"`, com a mesma semântica do campo de rádio.

### Melhorias

- **Campos:** o campo de avatar foi redesenhado: a foto (96px) é o próprio controle, com "Trocar foto" ao passar o mouse, remoção no canto e dica curta em português.

### Correções

- **Formulário:** múltipla seleção com `mode="table"` não salvava as marcações e abria tudo desmarcado na edição, apagando as seleções no próximo salvar. `foreign-key` agora é opcional e derivada por convenção (`pessoa` → `pessoa_id`).
- **Upload:** arquivo enviado para uma pasta personalizada (`folder`) salvava, mas o preview voltava erro 403 e a imagem aparecia quebrada.

## [5.50.4] — 2026-08-10

### Novidades

- **Dashboard:** o app pode registrar um hook que recebe os filtros globais do dashboard não consumidos pelo widget e os aplica ao escopo dos dados, re-agregando gráficos e KPIs.

## [5.50.3] — 2026-08-09

### Correções

- **Dashboard:** filtro global criado direto no gerenciador de dashboards era ignorado pelos widgets; agora é aplicado.

## [5.50.2] — 2026-08-09

### Melhorias

- **Dashboard:** os filtros sugeridos passam a oferecer as opções reais dos dados (coluna de texto com poucos valores vira lista de seleção), e filtrar por uma coluna do resultado agora funciona nos widgets agregados.

## [5.50.1] — 2026-08-09

### Novidades

- **Dashboard:** filtros sugeridos automaticamente a partir dos parâmetros do widget (mês vira lista 1-12, UF vira as 27 UFs); basta clicar para adicionar.

## [5.50.0] — 2026-08-09

### Novidades

- **Dashboard:** a tela Meus Dashboards ganha filtros globais persistidos, com vínculo filtro→parâmetro por widget, e edição de aparência do widget (unidade, cores, orientação, valor e rótulo central, nota).

## [5.49.2] — 2026-08-09

### Melhorias

- **Dashboard:** pedir um "dashboard completo" à IA passa a gerar pelo menos 8 widgets variados, cruzando dimensões e com títulos que indicam o recorte.

### Correções

- **Dashboard:** KPIs de widget mostravam número cru; agora formatam em pt-BR (66983.6 vira "66.983,60") e moeda vira "R$ …".

## [5.49.1] — 2026-08-08

### Correções

- **Dashboard:** a construção de um dashboard completo pela IA parava antes de salvar por estourar o limite de etapas.

## [5.49.0] — 2026-08-08

### Novidades

- **Chat:** widgets de BI salvos pelo agente podem usar uma consulta de ferramenta em vez de SQL, respeitam as permissões de quem visualiza e aceitam filtros (mês, UF) na tela "Meus Dashboards".
- **Chat:** o agente monta um dashboard persistente a partir dos widgets salvos; o layout pode ser ajustado no editor de grade da tela.

## [5.48.0] — 2026-08-08

### Melhorias

- **Chat:** ao terminar uma análise, o agente oferece os próximos passos como chips clicáveis, em vez de uma pergunta escondida no fim do texto.

## [5.47.0] — 2026-08-08

### Melhorias

- **Chat:** o copiloto do Mad Coding Plan não trava mais: resposta que anuncia uma ação sem executá-la, turno vazio, repetição da mesma chamada e transmissão parada são detectados e retomados.

## [5.46.1] — 2026-08-08

### Correções

- **Chat:** em tabelas agrupadas, valores numéricos que chegavam como texto ficavam de fora do subtotal e do total.

## [5.46.0] — 2026-08-08

### Novidades

- **Chat:** tabelas do chat aceitam agrupamento por coluna, com contagem por grupo, subtotais e total geral formatados em pt-BR.

## [5.45.0] — 2026-08-08

### Novidades

- **Chat:** quando falta um parâmetro-chave (período, unidade, recorte), o agente pergunta com opções clicáveis em vez de assumir um valor.

## [5.44.0] — 2026-08-08

### Novidades

- **Chat:** cinco visualizações novas: barras multi-série (agrupadas ou empilhadas), dispersão, mapa de calor, combinado barras + linha com eixo duplo e mapa do Brasil por UF.

## [5.43.0] — 2026-08-08

### Novidades

- **Chat:** favoritos do chat embutido passam a funcionar: salvar, listar, remover e reexecutar um bloco salvo sem consultar a IA de novo.

## [5.42.0] — 2026-08-08

### Novidades

- **Chat:** o copiloto embutido pode rodar com ferramentas pelo Mad Coding Plan, executando as consultas dentro do próprio app.
- **Chat:** o chat embutido passa a expor a lista de ferramentas disponíveis (somente leitura, filtradas por permissão) e a execução unitária, para integrações no cliente.

### Correções

- **Chat:** o chat respondia pela empresa ou filial errada; o contexto de empresa/filial de quando o token foi emitido agora vale na resposta.
- **Chat:** em PostgreSQL, o chat embutido falhava com "Token MCP ausente" porque não conseguia emitir tokens.
- **Banco de dados:** conexões adicionais de modelo de dados não apareciam no Database Manager, e a chave dele deixa de nascer presa à conexão padrão.

## [5.41.0] — 2026-08-03

### Correções

- **Campos:** com auto-criação de cidade pelo CEP, o combo de cidade mostrava o número do registro (ou ficava na primeira opção) em vez do nome; agora o nome aparece corretamente.

## [5.40.3] — 2026-08-03

### Correções

- **Campos:** `mask="cpfcnpj"` apagava o campo ao digitar o 12º dígito, e o cursor pulava para o fim a cada tecla em CNPJ, inclusive na planilha.

## [5.40.2] — 2026-08-03

### Correções

- **Campos:** máscara e força de digitação passam a ligar em qualquer campo inserido na tela, venha de drawer, aba, atualização reativa ou HTML injetado pelo próprio app.

## [5.40.1] — 2026-08-03

### Correções

- **Campos:** `mask` e `force-case` não funcionavam em telas abertas por navegação, drawer, modal ou aba, só ao abrir a URL direto. Busca e `select-check` também não iniciavam em drawer e modal.

## [5.40.0] — 2026-08-02

### Novidades

- **Detalhe (mestre/detalhe):** `<mad-field-list-column type="text">` aceita `mask`, `strip-mask`, `force-case`, `icon`, `icon-color`, `icon-side`, `max-width`, `maxlength` e `toggle-password` (texto, e-mail, telefone e senha); `strip-mask` grava sem máscara sozinho.

### Correções

- **Detalhe (mestre/detalhe):** linha nova do `<mad-field-list>` não recebia máscara, e o valor mascarado na tela divergia do valor guardado na linha (afetava cálculo, `on-change` e o salvamento).

## [5.39.0] — 2026-08-02

### Correções

- **Campos:** `height` no `<mad-textarea-field>` não fazia nada; agora a altura vale (número puro em px, `calc()` aceito).

## [5.38.0] — 2026-08-01

### Novidades

- **PDV:** `customer-order-by` aceita direção (`nome desc`); valor malformado aparece no painel de erro de configuração.

### Correções

- **Combo:** a direção ASC/DESC de `order` nunca ordenava nos combos de banco (`dbcombo`, `dbradio`, `dbcheckbox-group`, `dbselect-check`, `dbsort-list`, `dbunique-search`, `dbmulti-search`), nem na cascata nem na busca.
- **Detalhe (mestre/detalhe):** a direção de ordenação da coluna `type="dbcombo"` do `<mad-field-list>` era descartada.

## [5.37.0] — 2026-07-31

### Novidades

- **Campos:** `<mad-cnpj-field>` ganhou a resolução de cidade/estado que o builder já oferecia, com os mesmos props do CEP (`city-model`, `state-create` etc.).
- **Campos:** `city-scope-by-state` restringe a busca da cidade ao estado resolvido, e a auto-criação de cidade/estado pode ser ligada por projeto na configuração do app.

### Correções

- **Campos:** a auto-criação de cidade/estado falhava em silêncio (combo vazio) quando faltava coluna obrigatória; agora o erro é informado. Estado criado sem a cidade não fica mais órfão.
- **Campos:** a busca de cidade/estado existente podia devolver o registro de outra empresa/filial; agora respeita o escopo e reaproveita registro excluído quando existir.
- **Campos:** a prop `database` do CEP/CNPJ era ignorada; a cascata estado→cidade funciona com `city-target` customizado; com dois formulários na mesma página, o preenchimento ia para o primeiro.

## [5.36.0] — 2026-07-31

### Novidades

- **App gerado:** o app pode ser servido sob um prefixo de caminho (ex.: `/mini-crm`), não só na raiz do domínio; CSS, JS, fontes e notificações resolvem corretamente. Mantenha o `DirectorySlash On` do Apache.

## [5.35.6] — 2026-07-31

- Manutenção interna, sem mudança visível.

## [5.35.5] — 2026-07-31

- Manutenção interna, sem mudança visível.

## [5.35.4] — 2026-07-31

- Manutenção interna, sem mudança visível.

## [5.35.3] — 2026-07-31

- Manutenção interna, sem mudança visível.

## [5.35.2] — 2026-07-31

### Melhorias

- **PDV:** a documentação passa a avisar que `<mad-pdv-payment>` e `<mad-pdv-column>` não podem ser condicionados com `@if`; condicione pelos atributos (`:gera-titulo="$cond"`).

## [5.35.1] — 2026-07-31

### Correções

- **PDV:** o catálogo de produtos não adicionava o item ao clicar nem virava a página, e o bloco de parcelamento do cliente não aparecia.

## [5.35.0] — 2026-07-31

### Novidades

- **Combo:** `search-columns` em `<mad-dbunique-search-field>` e `<mad-dbmulti-search-field>` separa as colunas pesquisadas do rótulo exibido; a busca também aceita o termo só com dígitos (CNPJ, telefone).

## [5.34.0] — 2026-07-31

### Novidades

- **PDV:** venda a prazo no caixa: `<mad-pdv-payment gera-titulo sacado="cliente" max-parcelas="12">` oferece parcelamento com prévia de datas e valores e grava as parcelas em contas a receber junto com a venda.

## [5.33.0] — 2026-07-31

### Novidades

- **PDV:** `transform-target="stored"` faz a transformação da coluna valer no valor gravado, e `affects="price"` deixa ela alterar o preço unitário (promoção, acréscimo por forma). Transformação com erro mantém o preço original.

## [5.32.0] — 2026-07-31

### Novidades

- **PDV:** `<mad-pdv-column mode="input" item-field="vendedor">` cria uma célula editável por item (vendedor, observação, número de série), validada no servidor e gravada na tabela de itens.
- **PDV:** bipar o mesmo produto com valores de entrada diferentes mantém linhas separadas; `merge-ignore` tira uma coluna da comparação e `merge-lines="off"` desliga a fusão.

### Correções

- **PDV:** venda em espera retomada podia colocar valores digitados na linha errada.

## [5.31.0] — 2026-07-31

### Novidades

- **PDV:** `<mad-pdv-column>` permite escolher as colunas do carrinho, com `field` (inclusive caminho por relacionamento, `{categoria->nome}`), `label`, `width`, `align`, `slot` e `transform`. Sem declarar, o conjunto padrão continua.

## [5.30.0] — 2026-07-31

### Novidades

- **PDV:** catálogo de produtos navegável para quem não tem leitor: botão na toolbar ou `F3` abre a lista com paginação e busca por texto. Props `product-picker`, `product-picker-hotkey` e `product-picker-page-size` (máx. 60).

## [5.29.0] — 2026-07-31

### Melhorias

- **PDV:** no pagamento composto, a lateral mostra o total "Pago" além do restante, cada entrada exibe o próprio troco e `Esc` desfaz a última entrada.

## [5.28.2] — 2026-07-31

### Correções

- **PDV:** todos os preços no dropdown de busca de produto apareciam como R$ 0,00.

## [5.28.1] — 2026-07-31

### Correções

- **PDV:** as props `price-database`, `stock-database`, `sale-database`, `item-database`, `payment-database` e `customer-database` eram ignoradas; a conexão declarada agora vale.

## [5.28.0] — 2026-07-31

### Correções

- **Detalhe (mestre/detalhe):** `width` (ou `size`) em `<mad-detail-form mode="drawer">` era ignorado e a gaveta abria sempre em 420px; aceita `sm|md|lg|xl|full` ou medida livre (`70%`, `820px`).

## [5.27.0] — 2026-07-31

### Novidades

- **PDV:** desconto em R$ ou %: `discount-input` aceita `money` (padrão), `percent`, `both` (botão R$/% no campo, que converte o valor digitado) ou `off` (desliga o desconto). Em percentual, uma dica mostra o valor em dinheiro.

## [5.26.1] — 2026-07-31

### Melhorias

- **PDV:** produto sem preço cadastrado avisa "Produto sem preço cadastrado" em vez de "Produto não encontrado"; na finalização, itens que perderam o preço são removidos do carrinho com aviso.

## [5.26.0] — 2026-07-31

### Novidades

- **PDV:** preço e estoque podem vir de tabelas relacionadas (preço com vigência, saldo por depósito) com `price-model`, `price-key`, `price-where`, `price-order`, `stock-model`, `stock-key` e `stock-where`; sem elas, tudo segue como antes.
- **PDV:** com saldo por depósito, a baixa de estoque é feita na linha do depósito certo; linha duplicada de saldo aborta a venda em vez de baixar mais de uma vez.

## [5.25.1] — 2026-07-31

- Manutenção interna, sem mudança visível.

## [5.25.0] — 2026-07-31

### Novidades

- **PDV:** `<mad-pdv>` ganha o caixa completo no navegador: scan bar com multiplicador (`3*`), formas de pagamento com troco (`allow-change`), atalhos F2/F4/F6/F8/F10, segurar e retomar venda, CPF na nota e impressão de cupom 58/80mm.

## [5.24.2] — 2026-07-30

### Novidades

- **PDV:** `<mad-pdv>` agora localiza produtos por código de barras ou busca e finaliza a venda validando preço, desconto, CPF/CNPJ e pagamentos no servidor, sem deixar o estoque negativo.
- **PDV:** venda enviada duas vezes não é duplicada; qualquer erro desfaz a venda inteira e mantém o carrinho. Há ganchos para plugar a emissão fiscal após a venda.

## [5.24.1] — 2026-07-30

### Novidades

- **PDV:** a tela do `<mad-pdv>` ganha barra de leitura, carrinho, totais, cliente, pagamentos com troco, vendas em espera e cupom não fiscal.
- **PDV:** configuração incompleta mostra um painel listando as pendências em vez de tela em branco; sem formas de pagamento declaradas, valem as quatro padrão.

## [5.24.0] — 2026-07-30

### Novidades

- **PDV:** nova tag `<mad-pdv>`, com `<mad-pdv-payment>` e `<mad-pdv-action>`, para telas de frente de caixa. Sub-tag desconhecida gera aviso em vez de quebrar a tela. Textos em quatro idiomas.

## [5.23.1] — 2026-07-30

### Correções

- **Campos:** props de dimensão (`width`, `height`, `size`, `max-width`, `max-height`) com valor numérico passam a valer em drawer, modal, skeleton, transporter, checklist, imagem, assinatura, avatar, pivot e gráficos.
- **Listagem (grid):** coluna de `<mad-grid>` e `<mad-detail-form>` com `width` numérico perdia a largura no cabeçalho.

## [5.23.0] — 2026-07-29

### Correções

- **Campos:** `width` e `max-width` numéricos (ex.: `width="200"`) não funcionavam em nenhum campo de formulário, que ficava no tamanho automático.
- **Planilha:** `<mad-sheet-col width="140">` perdia a largura da coluna.
- **Listagem (grid):** coluna definida por configuração perdia a largura na exportação em PDF/HTML.
- **Campos:** valor de dimensão não permite mais injetar estilos arbitrários no campo.

## [5.22.0] — 2026-07-29

### Melhorias

- **Layout:** toda prop de dimensão (`width`, `height`, `gap`, `template`) segue a mesma regra: número vale como pixels, unidade explícita é respeitada e valor irreconhecível é ignorado sem quebrar o estilo.

### Correções

- **Formulário:** `template="140 1fr"` em `<mad-form-grid>` empilhava todos os campos numa coluna; uma largura mal escrita agora afeta só a própria coluna.
- **Listagem (grid):** largura de coluna com valor numérico em texto era ignorada em grids montadas em PHP.
- **Dashboard:** `height="300px"` em `<mad-chart>`, `<mad-db-chart>` e no card do kanban perdia a altura.

## [5.21.1] — 2026-07-29

### Correções

- **Detalhe (mestre/detalhe):** `width` numérico numa coluna de `<mad-field-list>` empilhava o componente inteiro. Agora `140` vale como `140px`; largura irreconhecível afeta só a própria coluna e gera aviso.

## [5.21.0] — 2026-07-29

### Novidades

- **Campos:** suporte ao CNPJ alfanumérico (Receita Federal, julho/2026): `<mad-cnpj-field>` aceita letras na digitação, inclusive no celular, e validação, formatação, consulta e células de `<mad-sheet>` reconhecem o novo formato.

### Melhorias

- **Campos:** a consulta de CEP/CNPJ passa a usar a API da plataforma com o token do projeto e exige plano ativo; sem plano, o campo exibe a mensagem do servidor.
- **Campos:** consulta de CEP que falha responde em até 12 segundos, em vez de deixar o campo carregando por 30.

### Correções

- **Campos:** `<mad-cep-field>` e `<mad-cnpj-field>` haviam parado de consultar em todos os ambientes, com o erro "Configuração da API de CEP ausente".
- **Layout:** o atalho da Central de Comando no cabeçalho voltou a aparecer para administradores com permissão em apps de produção.

## [5.20.0] — 2026-07-29

### Melhorias

- **Tema:** a ordem das camadas flutuantes (dropdown, popover, seletor, carregando) passa a vir de variáveis de tema, facilitando ajustes em temas personalizados.

### Correções

- **Combo:** a lista de um combo dentro de `<mad-quick-form>` abria atrás do popover e não aparecia; o mesmo valia para menu de ações da grid, autocomplete e outras camadas flutuantes.

## [5.18.2] — 2026-07-29

### Correções

- **Central de Comando:** os botões "Aplicar menus", "Aplicar permissões", "Atualizar framework" e "Ver diff" não faziam nada ao clicar. Aplicar código também falhava em servidores com funções de shell desabilitadas.
- **Central de Comando:** o card Permissões dizia "em dia" com telas novas pendentes; agora cada programa novo ganha selo "novo" e grupos de módulo também abrem. Cards nunca executados mostram "Nunca executado".
- **Central de Comando:** a atualização automática gravava as permissões novas mas não as aplicava, deixando telas novas com acesso negado.

## [5.18.1] — 2026-07-29

### Correções

- **Combo:** combo dentro de `<mad-quick-form>` abria com a lista desatualizada; um registro criado na mesma tela não aparecia. A lista agora é recarregada ao abrir.

## [5.18.0] — 2026-07-28

### Novidades

- **Listagem (grid):** `filter-order` (`asc` ou `desc`) define a direção das opções do filtro de coluna; antes `filter-order-by` era sempre crescente.
- **Listagem (grid):** `<mad-col-filter>` passa a aceitar `placeholder` e `order`.

### Correções

- **Listagem (grid):** `filter-placeholder` era ignorado nos filtros `select`, `dbcombo`, `bool`, `multi` e `dbsearch`. Apps que já tinham a prop nesses tipos passam a exibi-la.
- **Listagem (grid):** `filter-database` em filtro `dbcombo`/`multi` não tinha efeito; opções e rótulos vinham da conexão padrão.

## [5.17.1] — 2026-07-28

### Correções

- **Combo:** combo dentro de `<mad-quick-form>` vinha como seleção nativa, sem busca nem teclado; clicar numa opção fechava o formulário, `Esc` fechava tudo e a lista abria atrás do popover.

## [5.17.0] — 2026-07-28

### Novidades

- **Combo:** "Cadastrar novo" fecha o ciclo: o formulário de destino pode devolver o registro criado ao combo de origem, que o seleciona e fecha o drawer. O termo buscado já vem preenchido.
- **Reatividade (MadWire):** a resposta de uma ação pode acrescentar uma única opção a um combo e selecioná-la, sem recarregar a lista inteira.

### Correções

- **Combo:** recarregar combo não funcionava em seleção múltipla (`select-check`, `dbselect-check`, `multi-search`, `dbmulti-search`) e podia atingir o combo errado quando um drawer repetia o campo.

## [5.16.0] — 2026-07-28

### Correções

- **Rotas:** clique no card do `<mad-kanban>`, no nó do `<mad-org-chart>`, no `<mad-transporter>` e o `action` do `<mad-input-field>` davam 404 em telas com rota amigável.

## [5.15.1] — 2026-07-28

### Correções

- **Combo:** o botão "Cadastrar novo" do bloco sem resultados dava 404 quando o formulário de destino usa rota amigável. Vale para dbcombo, select, db-search, multi-search e select-check.

## [5.15.0] — 2026-07-28

### Novidades

- **Rotas:** cada método de uma página pode ter caminho próprio (ex.: `/clientes/aprovar`), como o editor já mostrava; as rotas `/app/{slug}/{metodo}` continuam válidas. Ao declarar à mão, registre antes da rota da classe.

## [5.14.0] — 2026-07-28

### Novidades

- **Formulário:** `color` no `<mad-btn>` passa a colorir o ícone (`icon` e `iconEnd`); o editor já oferecia "Cor do ícone" e o valor era ignorado. Valores inválidos são descartados.

## [5.13.1] — 2026-07-28

### Correções

- **Rotas:** `navigate="Classe::onShow()"` (método sem parâmetro, como o editor grava) gerava 404 no `<mad-btn>` e não fazia nada em grid, field-list e tree.

## [5.13.0] — 2026-07-28

### Novidades

- **Formulário:** `<mad-btn>` ganha `label` (texto ao lado do ícone) e `confirm` (pergunta antes de executar a ação); antes as duas props eram ignoradas. Com `variant="danger"` o diálogo sai em vermelho.

## [5.12.1] — 2026-07-28

### Correções

- **Detalhe (mestre/detalhe):** coluna com caminho de relacionamento (`field="{cidade->estado->pais->nome}"`) vinha vazia em `<mad-detail-form>` e `<mad-field-list>`; funcionava só na grid. O valor é só exibição e não entra no salvar da linha.

## [5.12.0] — 2026-07-28

### Novidades

- **Listagem (grid):** `filter-type` traz 10 tipos de filtro de coluna (`text`, `select`, `dbcombo`, `dbsearch`, `multi`, `date`, `date-range`, `number`, `number-range`, `bool`), com `filter-model`, `filter-display`, `filter-order-by`, `:filter-filters`, `filter-min-length` e `filter-true`/`filter-false`.
- **Listagem (grid):** o mesmo filtro pode ser declarado com a tag filha `<mad-col-filter type="dbcombo" model="Estado" />` ou na forma fluente em PHP; corpo livre continua vencendo o `type`.

### Melhorias

- **Listagem (grid):** opções de filtro deixaram de recarregar a cada render, e o rótulo do filtro ativo sai numa consulta só.

### Correções

- **Listagem (grid):** `<mad-col-filter>` com valor contendo `>` (ex.: `field="{estado->nome}"`) perdia os atributos seguintes; `filter-opts="A:Ativo|I:Inativo"` sem bind PHP gerava erro.

## [5.11.1] — 2026-07-28

### Correções

- **Listagem (grid):** `filter="select"` e `filter="date"` filtravam como texto parcial: status `A` casava qualquer nome com a letra. O operador agora vem da declaração da coluna; quem dependia do antigo declara `filter-op="like"`.
- **Listagem (grid):** filtro com valor `0` (ex.: booleano "Não") era descartado.
- **Listagem (grid):** filtro só aceita colunas que declararam filtro e operadores conhecidos; antes operador inválido filtrava por outra coisa em silêncio.
- **Listagem (grid):** coluna chamada `total` com filtro ativo bagunçava o rodapé de paginação.

## [5.11.0] — 2026-07-28

### Correções

- **Listagem (grid):** `<mad-grid-filters>` (e os pares de kanban, calendário e gantt) escrito dentro do componente que filtra não aparecia na tela; agora funciona igual à forma fora, inclusive com `style="sidebar-*"`.

## [5.10.0] — 2026-07-28

### Novidades

- **Detalhe (mestre/detalhe):** `<mad-field-list>` ganha cabeçalho de dois níveis com `<mad-field-list-group label="...">` e `hint` por coluna (tooltip no cabeçalho). Conteúdo não reconhecido dentro da tag gera aviso em vez de sumir.

### Correções

- **Detalhe (mestre/detalhe):** `<mad-field-list>` sem atributos não compilava e a tela quebrava com "components.field-list-column not found".
- **Detalhe (mestre/detalhe):** duas colunas de `<mad-field-list>` ou `<mad-detail-form>` viravam uma quando a primeira não era self-closing; `<mad-color-field>` solto no `<mad-detail-form>` virava coluna fantasma.

## [5.9.1] — 2026-07-27

### Correções

- **Rotas:** `navigate` e `target` aceitam valor dinâmico (`:navigate="$expr"` ou `{{ $expr }}`); cards e atalhos gerados dentro de `@foreach` abriam um link morto. Nessa forma, informe o método com `method="…"`.

## [5.9.0] — 2026-07-25

### Novidades

- **Comentários e anexos:** nova prop `confirm-remove` em `<mad-comments>`, `<mad-attachments>` e `<mad-db-blocks>` pede confirmação antes de apagar um item. Sem valor usa a mensagem padrão; com texto usa a sua. Desligada por padrão.

## [5.8.1] — 2026-07-25

### Correções

- **Comentários e anexos:** adicionar item em `<mad-comments>` ou `<mad-attachments>` respondia "Método não permitido: blockAdd" em telas sem configuração extra no controller.

## [5.8.0] — 2026-07-25

### Correções

- **Formulário:** referenciar outra tela com o módulo errado no `use` não derruba mais o Salvar (o registro era gravado e a tela mostrava erro). A tela é resolvida pelo nome; corrija a referência mesmo assim.
- **Formulário:** salvar apontando para uma tela que não existe mostra uma mensagem que explica como referenciá-la, em vez de erro de classe não encontrada.

## [5.7.0] — 2026-07-25

### Melhorias

- **Reatividade (MadWire):** relações já carregadas de um Model em prop pública são mantidas entre requisições; antes cada atualização da tela refazia a consulta ou falhava com lazy loading bloqueado. Só relações de primeiro nível.

## [5.6.0] — 2026-07-25

### Correções

- **Detalhe (mestre/detalhe):** falha ao carregar as linhas do detalhe (conexão instável, modelo não encontrado) abria a tela vazia e, ao salvar, apagava todos os filhos do registro. Agora nada é apagado nesse caso.
- **Upload:** no envio de vários arquivos, uma falha ao gravar o primeiro fazia o segundo herdar o caminho dele.

## [5.5.0] — 2026-07-25

### Correções

- **App gerado:** uma tela com erro de compilação (por exemplo, assinatura de método incompatível) derrubava o app inteiro com erro 500 e travava as migrações. Agora só a própria tela fica indisponível.

## [5.4.4] — 2026-07-24

### Correções

- **Upload:** com `name-column` informada, a coluna de convenção `original_name` também é preenchida; em tabelas com as duas colunas obrigatórias, o anexo não gravava.

## [5.4.3] — 2026-07-24

### Correções

- **Comentários e anexos:** o upload por item falhava em tabelas com colunas de arquivo obrigatórias; o arquivo agora é gravado junto com o registro, e vários arquivos mantêm a ordem escolhida.
- **Comentários e anexos:** `name-column` recebe o nome original do arquivo quando `original-name-column` não é informada; antes ficava vazia e o anexo não gravava.

## [5.4.2] — 2026-07-24

### Correções

- **Comentários e anexos:** `<mad-comments>` e `<mad-attachments>` sem `record-id` numa tela de detalhe não gravavam, porque a chave do registro pai saía vazia; o registro atual passa a ser identificado automaticamente.
- **Comentários e anexos:** em tela com formulário, o texto do comentário era descartado ao gravar e a linha ficava só com a referência ao registro pai.

## [5.4.1] — 2026-07-24

### Correções

- **Comentários e anexos:** `<mad-comments>` e `<mad-attachments>` mostravam a lista mas não o formulário; as props de formulário e upload da 5.4.0 eram ignoradas. `:editable="$expr"` também passa a respeitar a expressão.
- **App gerado:** atualizar o framework passa a recompilar as telas; antes o app continuava servindo a versão compilada antiga mesmo depois do upgrade.

## [5.4.0] — 2026-07-24

### Novidades

- **Comentários e anexos:** novas tags `<mad-comments>` e `<mad-attachments>`: lista de itens de um registro com formulário embutido, sem código no controller. Anexos já incluem upload, download e exclusão do arquivo.
- **Comentários e anexos:** `add-mode="inline"` mantém o formulário fixo na tela (`form-position="below|above"`) e o limpa após gravar.
- **Comentários e anexos:** upload por item em `<mad-db-blocks>` (`file-field`, `folder`, `path-column`): vários arquivos geram um registro cada, e excluir o item apaga o arquivo. Valem as mesmas regras de nome e extensão do formulário.
- **Comentários e anexos:** itens existentes podem ser editados com o mesmo formulário de inclusão, e `:preset-vars` entrega valores livres ao template do item.

## [5.3.1] — 2026-07-24

### Correções

- **Upload:** em `mode="table"`, a coluna de disco gravava o disco padrão do sistema em vez do realmente usado, e o arquivo não era encontrado para servir ou apagar.

## [5.3.0] — 2026-07-24

### Novidades

- **Upload:** `mode="table"` grava os metadados do arquivo (nome original, tamanho, tipo e disco) via `original-name-column`, `size-column`, `mime-column` e `disk-column`, ou por convenção quando a coluna existe. Tabelas com essas colunas obrigatórias não falham mais ao anexar.
- **Upload:** os arquivos enviados pelo formulário ficam acessíveis no controller, para gravação em destino fora do padrão.

## [5.2.0] — 2026-07-23

### Correções

- **Reatividade (MadWire):** um Model Eloquent em prop pública tipada causava erro 500 na atualização da tela; agora é mantido entre requisições. Relações precisam ser recarregadas a cada requisição.
- **Reatividade (MadWire):** estado incompatível com o tipo da prop (dados antigos, classe removida) é descartado com aviso em vez de derrubar a requisição.

## [5.1.0] — 2026-07-23

### Novidades

- **App gerado:** erro de servidor (500) em ações da tela abre um modal com a página de erro, o texto extraído e um Markdown pronto para colar no agente de IA; antes só aparecia no console do navegador. Detalhes só em modo debug.

## [5.0.0] — 2026-07-23

### Novidades

- **Detalhe (mestre/detalhe):** novas props por linha em `<mad-field-list>`: `disabled-when`, `readonly-when`, `required-when` e `visible-when`, com `{campo}` lendo o valor da própria linha.
- **Detalhe (mestre/detalhe):** `options` de coluna aceita mapa, lista valor-rótulo, JSON do editor visual ou forma compacta; antes uma string derrubava a tela.
- **Detalhe (mestre/detalhe):** `attrs` e `on-change` funcionam igual em todos os tipos de célula, e o seletor %/R$ do desconto respeita `disabled`.
- **App gerado:** o framework passa a usar versionamento semântico; esta é a versão base da geração Laravel/Eloquent.

## [v1.2.0-db-eloquent] — 2026-06-19

### Novidades

- **Listagem (grid):** exportação para XLSX e PDF passa a exigir autenticação, com link que expira.
- **Upload:** upload por linha em `<mad-field-list>` e `<mad-detail-form>`, vários arquivos gravados em tabela e download de arquivos armazenados no banco.
- **Permissões:** escopo de linha por usuário ou unidade no MCP, opcional e fechado por padrão.

### Melhorias

- **Listagem (grid):** filtros de coluna, subconsultas relacionais e ordenação são parametrizados e validados contra injeção de SQL; o mesmo vale para os campos de cor, grupo e valor dos gráficos.

### Correções

- **Formulário:** exclusão em cascata de registros em árvore (auto-referente) falhava com erro.
- **Comentários e anexos:** violação de unicidade ao gravar item é detectada e reportada corretamente.

### Atenção

- **App gerado:** camada de dados legada removida: models são Eloquent nativos, transações são as do Laravel e `:criteria`, `:query` e `:filters` recebem query builder. Adapte código próprio que usava as classes antigas.
- **Combo:** `dbselect` dá lugar a `dbcombo`, e carregar opções a partir de um model não recebe mais o argumento de conexão; remova-o das chamadas.

## [v1.1.0-correio] — 2026-06-14

### Novidades

- **Correio:** correio interno reescrito no estilo Gmail: pastas Caixa, Favoritos, Enviadas, Rascunhos, Arquivadas e Lixeira, rótulos, Cc, rascunhos, anexos, responder e responder a todos. Ler uma mensagem não a arquiva mais.
- **Correio:** lido, favorito e arquivado são individuais por destinatário, e novas mensagens chegam em tempo real (badge, aviso e lista) sem recarregar a página.
- **Chat:** chat interno em tempo real, com conversas privadas, reações e anexos, substituindo o chat baseado em Firebase.

### Melhorias

- **Correio:** cada usuário só enxerga mensagens de que participa; envio para destinatários fora da própria unidade é bloqueado no servidor, e o conteúdo das mensagens é sanitizado contra XSS.
- **App gerado:** conexões de banco passam a ser configuradas no padrão do Laravel, sem arquivos de configuração próprios do framework.

### Correções

- **Correio:** a janela de composição envia corretamente, a troca entre lista e leitura ficou confiável e o menu de mensagens do cabeçalho abre a caixa nova.
