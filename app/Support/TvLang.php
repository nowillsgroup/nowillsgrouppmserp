<?php

namespace App\Support;

/**
 * v7.1 On-screen text of the in-room TV portal in six languages. Hotel content (welcome screens, information pages,
 * messages, menus) is written by the hotel; these are the fixed labels around it. English is the fallback.
 */
class TvLang
{
    public const LANGS = ['en' => 'English', 'fr' => 'Français', 'es' => 'Español', 'pt' => 'Português', 'de' => 'Deutsch', 'zh' => '中文'];

    /** nationality / country → language (anything not listed is English) */
    public const COUNTRY = [
        'france' => 'fr', 'french' => 'fr', "côte d'ivoire" => 'fr', "cote d'ivoire" => 'fr', 'ivory coast' => 'fr', 'ivorian' => 'fr', 'togo' => 'fr', 'togolese' => 'fr',
        'benin' => 'fr', 'beninese' => 'fr', 'burkina faso' => 'fr', 'burkinabe' => 'fr', 'senegal' => 'fr', 'senegalese' => 'fr', 'mali' => 'fr', 'malian' => 'fr',
        'niger' => 'fr', 'guinea' => 'fr', 'cameroon' => 'fr', 'gabon' => 'fr', 'congo' => 'fr', 'dr congo' => 'fr', 'democratic republic of the congo' => 'fr',
        'belgium' => 'fr', 'luxembourg' => 'fr', 'monaco' => 'fr', 'madagascar' => 'fr', 'chad' => 'fr', 'haiti' => 'fr', 'morocco' => 'fr', 'tunisia' => 'fr', 'algeria' => 'fr',
        'spain' => 'es', 'spanish' => 'es', 'mexico' => 'es', 'argentina' => 'es', 'colombia' => 'es', 'chile' => 'es', 'peru' => 'es', 'venezuela' => 'es', 'cuba' => 'es',
        'equatorial guinea' => 'es', 'ecuador' => 'es', 'uruguay' => 'es',
        'portugal' => 'pt', 'portuguese' => 'pt', 'brazil' => 'pt', 'brazilian' => 'pt', 'angola' => 'pt', 'mozambique' => 'pt', 'cape verde' => 'pt', 'cabo verde' => 'pt',
        'guinea-bissau' => 'pt', 'são tomé and príncipe' => 'pt',
        'germany' => 'de', 'german' => 'de', 'austria' => 'de', 'austrian' => 'de', 'liechtenstein' => 'de',
        'china' => 'zh', 'chinese' => 'zh', 'taiwan' => 'zh', 'hong kong' => 'zh', 'macau' => 'zh',
    ];

    public const STRINGS = [
        'en' => [
            'welcome' => 'Welcome', 'vacant' => 'Welcome to', 'continue' => 'Continue', 'home' => 'Home', 'tv' => 'Live TV', 'movies' => 'Movies', 'dining' => 'In-room dining',
            'services' => 'Services', 'messages' => 'Messages', 'bill' => 'My bill', 'checkout' => 'Express check-out', 'info' => 'Hotel guide', 'explore' => 'Explore Ghana',
            'feedback' => 'Rate your stay', 'language' => 'Language', 'room' => 'Room', 'departure' => 'Departure', 'wifi' => 'Wi-Fi', 'password' => 'Password',
            'back' => 'Back', 'ok' => 'OK', 'cancel' => 'Cancel', 'order' => 'Place order', 'add' => 'Add', 'cart' => 'Your order', 'total' => 'Total', 'send' => 'Send',
            'rent' => 'Rent', 'watch' => 'Watch', 'enter_pin' => 'Enter your 4-digit TV PIN (on your key-card wallet)', 'charged' => 'Charged to your room',
            'incl_tax' => 'incl. VAT & levies', 'now' => 'Now', 'next' => 'Next', 'no_messages' => 'No messages.', 'sent' => 'Sent – thank you. We are on it.',
            'wakeup' => 'Wake-up call', 'time' => 'Time', 'daily' => 'Every day', 'book' => 'Book', 'balance' => 'Balance', 'checkout_q' => 'Request express check-out? We will settle your bill and e-mail the invoice.',
            'checkout_done' => 'Express check-out requested. Leave your key card in the room or at the desk.', 'thanks' => 'Thank you!', 'emergency' => 'EMERGENCY',
            'all_clear' => 'All clear', 'events_today' => "Today's events", 'left' => 'left', 'hours' => 'h', 'unavailable' => 'Not available right now.',
            'error' => 'Something went wrong – please call the front desk.', 'all' => 'All', 'qty' => 'Qty', 'new_msg' => 'New message', 'rented' => 'Rented',
            'read_more' => 'Open', 'tuned' => 'Now showing', 'no_signal' => 'This channel plays on the TV tuner.', 'dial' => 'Dial', 'front_desk' => 'for the front desk',
        ],
        'fr' => [
            'welcome' => 'Bienvenue', 'vacant' => 'Bienvenue à', 'continue' => 'Continuer', 'home' => 'Accueil', 'tv' => 'TV en direct', 'movies' => 'Films', 'dining' => 'Service en chambre',
            'services' => 'Services', 'messages' => 'Messages', 'bill' => 'Ma facture', 'checkout' => 'Départ express', 'info' => "Guide de l'hôtel", 'explore' => 'Découvrir le Ghana',
            'feedback' => 'Notez votre séjour', 'language' => 'Langue', 'room' => 'Chambre', 'departure' => 'Départ', 'wifi' => 'Wi-Fi', 'password' => 'Mot de passe',
            'back' => 'Retour', 'ok' => 'OK', 'cancel' => 'Annuler', 'order' => 'Commander', 'add' => 'Ajouter', 'cart' => 'Votre commande', 'total' => 'Total', 'send' => 'Envoyer',
            'rent' => 'Louer', 'watch' => 'Regarder', 'enter_pin' => 'Saisissez votre code TV à 4 chiffres (sur la pochette de la carte-clé)', 'charged' => 'Facturé sur votre chambre',
            'incl_tax' => 'TVA et taxes incluses', 'now' => 'En ce moment', 'next' => 'Ensuite', 'no_messages' => 'Aucun message.', 'sent' => "Envoyé – merci. Nous nous en occupons.",
            'wakeup' => 'Réveil', 'time' => 'Heure', 'daily' => 'Tous les jours', 'book' => 'Réserver', 'balance' => 'Solde', 'checkout_q' => 'Demander un départ express ? Nous réglerons votre facture et vous l’enverrons par e-mail.',
            'checkout_done' => 'Départ express demandé. Laissez votre carte-clé dans la chambre ou à la réception.', 'thanks' => 'Merci !', 'emergency' => 'URGENCE',
            'all_clear' => 'Fin d’alerte', 'events_today' => "Événements du jour", 'left' => 'restantes', 'hours' => 'h', 'unavailable' => 'Indisponible pour le moment.',
            'error' => 'Une erreur est survenue – veuillez appeler la réception.', 'all' => 'Tout', 'qty' => 'Qté', 'new_msg' => 'Nouveau message', 'rented' => 'Loué',
            'read_more' => 'Ouvrir', 'tuned' => "À l'antenne", 'no_signal' => 'Cette chaîne est diffusée par le tuner du téléviseur.', 'dial' => 'Composez le', 'front_desk' => 'pour la réception',
        ],
        'es' => [
            'welcome' => 'Bienvenido', 'vacant' => 'Bienvenido a', 'continue' => 'Continuar', 'home' => 'Inicio', 'tv' => 'TV en directo', 'movies' => 'Películas', 'dining' => 'Servicio a la habitación',
            'services' => 'Servicios', 'messages' => 'Mensajes', 'bill' => 'Mi cuenta', 'checkout' => 'Salida exprés', 'info' => 'Guía del hotel', 'explore' => 'Descubra Ghana',
            'feedback' => 'Valore su estancia', 'language' => 'Idioma', 'room' => 'Habitación', 'departure' => 'Salida', 'wifi' => 'Wi-Fi', 'password' => 'Contraseña',
            'back' => 'Volver', 'ok' => 'Aceptar', 'cancel' => 'Cancelar', 'order' => 'Hacer pedido', 'add' => 'Añadir', 'cart' => 'Su pedido', 'total' => 'Total', 'send' => 'Enviar',
            'rent' => 'Alquilar', 'watch' => 'Ver', 'enter_pin' => 'Introduzca su PIN de TV de 4 dígitos (en el sobre de la tarjeta)', 'charged' => 'Cargado a su habitación',
            'incl_tax' => 'IVA e impuestos incluidos', 'now' => 'Ahora', 'next' => 'Después', 'no_messages' => 'No hay mensajes.', 'sent' => 'Enviado – gracias. Nos ocupamos.',
            'wakeup' => 'Despertador', 'time' => 'Hora', 'daily' => 'Todos los días', 'book' => 'Reservar', 'balance' => 'Saldo', 'checkout_q' => '¿Solicitar la salida exprés? Liquidaremos su cuenta y le enviaremos la factura.',
            'checkout_done' => 'Salida exprés solicitada. Deje la tarjeta en la habitación o en recepción.', 'thanks' => '¡Gracias!', 'emergency' => 'EMERGENCIA',
            'all_clear' => 'Fin de la alerta', 'events_today' => 'Eventos de hoy', 'left' => 'restantes', 'hours' => 'h', 'unavailable' => 'No disponible en este momento.',
            'error' => 'Algo salió mal – llame a recepción.', 'all' => 'Todo', 'qty' => 'Cant.', 'new_msg' => 'Mensaje nuevo', 'rented' => 'Alquilada',
            'read_more' => 'Abrir', 'tuned' => 'En emisión', 'no_signal' => 'Este canal se reproduce con el sintonizador del televisor.', 'dial' => 'Marque', 'front_desk' => 'para recepción',
        ],
        'pt' => [
            'welcome' => 'Bem-vindo', 'vacant' => 'Bem-vindo ao', 'continue' => 'Continuar', 'home' => 'Início', 'tv' => 'TV ao vivo', 'movies' => 'Filmes', 'dining' => 'Serviço de quarto',
            'services' => 'Serviços', 'messages' => 'Mensagens', 'bill' => 'A minha conta', 'checkout' => 'Check-out expresso', 'info' => 'Guia do hotel', 'explore' => 'Descubra o Gana',
            'feedback' => 'Avalie a sua estadia', 'language' => 'Idioma', 'room' => 'Quarto', 'departure' => 'Partida', 'wifi' => 'Wi-Fi', 'password' => 'Palavra-passe',
            'back' => 'Voltar', 'ok' => 'OK', 'cancel' => 'Cancelar', 'order' => 'Encomendar', 'add' => 'Adicionar', 'cart' => 'O seu pedido', 'total' => 'Total', 'send' => 'Enviar',
            'rent' => 'Alugar', 'watch' => 'Ver', 'enter_pin' => 'Introduza o seu PIN de TV de 4 dígitos (na capa do cartão)', 'charged' => 'Debitado no seu quarto',
            'incl_tax' => 'IVA e taxas incluídos', 'now' => 'Agora', 'next' => 'A seguir', 'no_messages' => 'Sem mensagens.', 'sent' => 'Enviado – obrigado. Estamos a tratar.',
            'wakeup' => 'Despertar', 'time' => 'Hora', 'daily' => 'Todos os dias', 'book' => 'Marcar', 'balance' => 'Saldo', 'checkout_q' => 'Pedir check-out expresso? Liquidamos a conta e enviamos a fatura.',
            'checkout_done' => 'Check-out expresso pedido. Deixe o cartão no quarto ou na receção.', 'thanks' => 'Obrigado!', 'emergency' => 'EMERGÊNCIA',
            'all_clear' => 'Fim do alerta', 'events_today' => 'Eventos de hoje', 'left' => 'restantes', 'hours' => 'h', 'unavailable' => 'Indisponível de momento.',
            'error' => 'Ocorreu um erro – ligue para a receção.', 'all' => 'Tudo', 'qty' => 'Qtd.', 'new_msg' => 'Nova mensagem', 'rented' => 'Alugado',
            'read_more' => 'Abrir', 'tuned' => 'Em exibição', 'no_signal' => 'Este canal é reproduzido pelo sintonizador da TV.', 'dial' => 'Marque', 'front_desk' => 'para a receção',
        ],
        'de' => [
            'welcome' => 'Willkommen', 'vacant' => 'Willkommen im', 'continue' => 'Weiter', 'home' => 'Start', 'tv' => 'Live-TV', 'movies' => 'Filme', 'dining' => 'Zimmerservice',
            'services' => 'Services', 'messages' => 'Nachrichten', 'bill' => 'Meine Rechnung', 'checkout' => 'Express-Check-out', 'info' => 'Hotelinformationen', 'explore' => 'Ghana entdecken',
            'feedback' => 'Aufenthalt bewerten', 'language' => 'Sprache', 'room' => 'Zimmer', 'departure' => 'Abreise', 'wifi' => 'WLAN', 'password' => 'Passwort',
            'back' => 'Zurück', 'ok' => 'OK', 'cancel' => 'Abbrechen', 'order' => 'Bestellen', 'add' => 'Hinzufügen', 'cart' => 'Ihre Bestellung', 'total' => 'Summe', 'send' => 'Senden',
            'rent' => 'Leihen', 'watch' => 'Ansehen', 'enter_pin' => 'Geben Sie Ihre 4-stellige TV-PIN ein (auf der Schlüsselkartenhülle)', 'charged' => 'Wird auf Ihr Zimmer gebucht',
            'incl_tax' => 'inkl. MwSt. und Abgaben', 'now' => 'Jetzt', 'next' => 'Danach', 'no_messages' => 'Keine Nachrichten.', 'sent' => 'Gesendet – danke. Wir kümmern uns darum.',
            'wakeup' => 'Weckruf', 'time' => 'Uhrzeit', 'daily' => 'Täglich', 'book' => 'Buchen', 'balance' => 'Saldo', 'checkout_q' => 'Express-Check-out anfordern? Wir begleichen Ihre Rechnung und senden sie per E-Mail.',
            'checkout_done' => 'Express-Check-out angefordert. Lassen Sie die Karte im Zimmer oder an der Rezeption.', 'thanks' => 'Vielen Dank!', 'emergency' => 'NOTFALL',
            'all_clear' => 'Entwarnung', 'events_today' => 'Veranstaltungen heute', 'left' => 'übrig', 'hours' => 'Std.', 'unavailable' => 'Derzeit nicht verfügbar.',
            'error' => 'Etwas ist schiefgelaufen – bitte rufen Sie die Rezeption an.', 'all' => 'Alle', 'qty' => 'Menge', 'new_msg' => 'Neue Nachricht', 'rented' => 'Ausgeliehen',
            'read_more' => 'Öffnen', 'tuned' => 'Läuft jetzt', 'no_signal' => 'Dieser Sender läuft über den TV-Tuner.', 'dial' => 'Wählen Sie', 'front_desk' => 'für die Rezeption',
        ],
        'zh' => [
            'welcome' => '欢迎', 'vacant' => '欢迎光临', 'continue' => '继续', 'home' => '主页', 'tv' => '电视直播', 'movies' => '电影', 'dining' => '客房送餐',
            'services' => '客房服务', 'messages' => '消息', 'bill' => '我的账单', 'checkout' => '快速退房', 'info' => '酒店指南', 'explore' => '探索加纳',
            'feedback' => '评价您的入住', 'language' => '语言', 'room' => '房间', 'departure' => '离店', 'wifi' => '无线网络', 'password' => '密码',
            'back' => '返回', 'ok' => '确定', 'cancel' => '取消', 'order' => '下单', 'add' => '添加', 'cart' => '您的订单', 'total' => '合计', 'send' => '发送',
            'rent' => '租看', 'watch' => '观看', 'enter_pin' => '请输入4位电视密码（见房卡套）', 'charged' => '计入您的房账',
            'incl_tax' => '含增值税及附加税', 'now' => '正在播出', 'next' => '稍后', 'no_messages' => '暂无消息。', 'sent' => '已发送，谢谢。我们马上处理。',
            'wakeup' => '叫醒服务', 'time' => '时间', 'daily' => '每天', 'book' => '预约', 'balance' => '余额', 'checkout_q' => '申请快速退房？我们将结清账单并通过电子邮件发送发票。',
            'checkout_done' => '已申请快速退房。请将房卡留在房间或前台。', 'thanks' => '谢谢！', 'emergency' => '紧急情况',
            'all_clear' => '警报解除', 'events_today' => '今日活动', 'left' => '剩余', 'hours' => '小时', 'unavailable' => '暂时无法使用。',
            'error' => '出现问题，请致电前台。', 'all' => '全部', 'qty' => '数量', 'new_msg' => '新消息', 'rented' => '已租',
            'read_more' => '打开', 'tuned' => '正在播放', 'no_signal' => '该频道通过电视调谐器播放。', 'dial' => '请拨', 'front_desk' => '联系前台',
        ],
    ];

    public static function strings(string $lang): array
    {
        return (self::STRINGS[$lang] ?? []) + self::STRINGS['en'];
    }

    public static function forCountry(?string $country): ?string
    {
        return $country ? (self::COUNTRY[mb_strtolower(trim($country))] ?? null) : null;
    }
}
