@include('errors.layout', [
    'codigo' => 404,
    'titulo' => 'Página não encontrada',
    'oQueAconteceu' => 'O endereço não existe ou o registro foi removido.',
    'oQueFazer' => 'Confira o link que você recebeu. Se veio de um e-mail antigo, o post ou o convite pode ter sido substituído por uma versão nova.',
    'rotulo' => 'Voltar ao início',
    'destino' => url('/'),
    'mensagem' => null,
])
