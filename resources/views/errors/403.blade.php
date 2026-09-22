@include('errors.layout', [
    'codigo' => 403,
    'titulo' => 'Você não tem acesso a isto',
    'oQueAconteceu' => 'A sua conta não tem permissão para esta página ou este registro pertence a outro cliente.',
    'oQueFazer' => 'Se acha que deveria ter acesso, peça a um gestor da agência para revisar o seu papel. Nada foi alterado.',
    'rotulo' => 'Voltar ao início',
    'destino' => url('/'),
    'mensagem' => ($exception ?? null)?->getMessage(),
])
