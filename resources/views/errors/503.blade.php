@include('errors.layout', [
    'codigo' => 503,
    'titulo' => 'O sistema está em manutenção',
    'oQueAconteceu' => 'Estamos atualizando o sistema; volta em poucos minutos.',
    'oQueFazer' => 'Aguarde e recarregue a página. Posts agendados continuam na fila e saem assim que a manutenção terminar.',
    'rotulo' => 'Tentar de novo',
    'destino' => url()->current(),
    'mensagem' => null,
])
