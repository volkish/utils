<?php


/**
 * Модуль отправки писем
 *
 * @package Delivery
 * @author Artem Ivanov <ai@artem.cc>
 * @rev: $REV$
 */
class SMTPMail
{
	const EOL = "\r\n";

	/**
	 * Разделитель
	 * @var string
	 */
	protected $boundary = "";

	/**
	 * Содержание
	 * @var array
	 */
	protected $message = array();

	/**
	 * Кодировка
	 * @var string
	 */
	protected $charset = "UTF-8";

	/**
	 * Дебаггер
	 * @var mixed
	 */
	protected $debugger;

	/**
	 * @var resource
	 */
	protected $socket;

	/**
	 * SMTP сервер
	 * @var string
	 */
	protected $smtp_server;

	/**
	 * Локальный хост
	 * @var string
	 */
	protected $localhost;

	/**
	 * @param string $smtp_server SMTP сервер
	 * @param string $localhost Сервер
	 * @param string $charset Кодировка
	 * @throws Exception
	 */
	public function __construct($smtp_server, $localhost = NULL, $charset = NULL)
	{
		if ($charset !== NULL) {
			$this->charset = $charset;
		}

		$this->smtp_server = $smtp_server;
		$this->localhost = $localhost;
		$this->boundary = "NextPart_" . md5(microtime());
	}

	/**
	 * Подключиться к SMTP серверу
	 * @return void
	 * @throws SMTPMail_Exception
	 */
	public function connect()
	{
		if ($this->isConnected()) {
			return ;
		}

		$this->socket = stream_socket_client("tcp://{$this->smtp_server}:25", $errno, $errmsg, 20, STREAM_CLIENT_CONNECT);

		if (! is_resource($this->socket)) {
			throw new SMTPMail_Exception("$errno: $errmsg");
		}

		stream_set_blocking($this->socket, TRUE);

		// Ждем привета
		$this->read(220);

		// Говорим привет в ответ
		$this->command("HELO", $this->localhost ? $this->localhost : $this->smtp_server);

		// Ждем ответ
		$this->read(250);
	}

	/**
	 * Проверить есть ли соединение
	 * @return bool
	 */
	public function isConnected()
	{
		return is_resource($this->socket);
	}

	/**
	 * Отключиться от SMTP сервера
	 */
	public function disconnect()
	{
		if ($this->isConnected()) {
			$this->command("QUIT");
			$this->read(221);

			fclose($this->socket);

			$this->socket = NULL;
		}
	}

	/**
	 * @static
	 * @param string $remote_socket
	 * @param string $localhost
	 * @param string $charset
	 * @return SMTPMail
	 */
	public static function factory($remote_socket, $localhost = NULL, $charset = NULL)
	{
		return new self($remote_socket, $localhost, $charset);
	}

	/**
	 * Включить отладку
	 * @param mixed $mixed_callback
	 * @return SMTPMail
	 */
	public function setDebugger($mixed_callback)
	{
		$this->debugger = $mixed_callback;

		return $this;
	}

	/**
	 * Посылка текстового содержимого письма
	 * @param string $text message body
	 * @return SMTPMail
	 */
	public function setText($text)
	{
		$this->message['text'] = $text;

		return $this;
	}

	/**
	 * Посылка HTML содержимого письма
	 * @param string $html body, in html format
	 * @return SMTPMail
	 */
	public function setHTML($html)
	{
		$this->message['html'] = $html;

		return $this;
	}

	/**
	 * Добавление файлов к письму
	 * @param string $content Содержание
	 * @param string $filename Название файла
	 * @param string $mime_type MIME тип (default 'application/octet-stream')
	 * @param string $disposition Размещение
	 */
	public function addAttachment($content, $filename, $mime_type = "application/octet-stream", $disposition = 'attachment')
	{
		$this->message['attach'][] = array('content'     => chunk_split(base64_encode($content)),
										   'filename'    => $filename,
										   'disposition' => $disposition,
										   'mime'        => $mime_type);
	}

	/**
	 * Создание сообщения и установка залоговков в зависимости от контента
	 *
	 * создаёт готовое тело сообщения со всеми заголовками и правильной структурой, подходящее для mail()
	 * @return string
	 */
	protected function getMessage()
	{
		if (! empty($this->message['attach'])) { // сообщение с аттачментами
			$msg = $this->getMultipartHeader();

			// HTML
			if (! empty($this->message['text'])) {
				$msg .= $this->getBoundaryStart();
				$msg .= $this->getPart($this->message['text'], 'text/plain');
			}

			// Текст
			if (! empty($this->message['html'])) {
				$msg .= $this->getBoundaryStart();
				$msg .= $this->getPart($this->message['html'], 'text/html');
			}

			// Аттачменты
			foreach ($this->message['attach'] as $file) {
				$msg .= $this->getBoundaryStart();
				$msg .= $this->getFilePart($file);
			}

			$msg .= $this->getBoundaryEnd();
		}
		else {
			// Письмо с мульти содержимым
			if (! empty($this->message['text']) AND ! empty($this->message['html'])) {
				$msg  = $this->getMultipartHeader();
				$msg .= $this->getBoundaryStart();
				$msg .= $this->getPart($this->message['text'], 'text/plain');
				$msg .= $this->getBoundaryStart();
				$msg .= $this->getPart($this->message['html'], 'text/html');
				$msg .= $this->getBoundaryEnd();
			}
			// Только HTML
			elseif (! empty($this->message['html'])) {
				$msg = $this->getPart($this->message['html'], 'text/html');
			}
			// Только текст
			else {
				$msg = $this->getPart($this->message['text'], 'text/plain');
			}
		}

		return rtrim($msg, self::EOL);
	}

	protected function getMultipartHeader()
	{
		return "Content-type: multipart/alternative; boundary={$this->boundary}" . self::EOL;
	}

	protected function getPart($content, $type)
	{
		return
			"Content-Transfer-Encoding: base64"               . self::EOL .
			"Content-Type: {$type}; charset={$this->charset}" . self::EOL . self::EOL .
			chunk_split(base64_encode($content))              . self::EOL . self::EOL;
	}

	protected function getFilePart(array $file)
	{
		return
			"Content-Transfer-Encoding: base64"                                         . self::EOL .
			"Content-Type: {$file['mime']}; name={$file['filename']}"                   . self::EOL .
			"Content-Disposition: {$file['disposition']}; filename={$file['filename']}" . self::EOL . self::EOL .
			$file['content']                                                            . self::EOL . self::EOL;
	}

	protected function getBoundaryStart()
	{
		return "--{$this->boundary}" . self::EOL;
	}

	protected function getBoundaryEnd()
	{
		return "--{$this->boundary}--" . self::EOL;
	}

	/**
	 * Перекодировка заголовка почты
	 * @param string $subject  Mail subject
	 * @return string encoded subject
	 */
	protected function getSubject($subject)
	{
		return '=?' . $this->charset . '?B?' . base64_encode($subject) . '?=';
	}

	/**
	 * Посылка почты получателю
	 * @param string $to Recipient's e-mail address
	 * @param string $subject Mail subject
	 * @param string $from
	 * @param string $from_username
	 * @throws SMTPMail_Exception
	 * @return bool
	 */
	public function send($to, $subject, $from = 'noreply@baby.ru', $from_username = 'бэби.ру')
	{
		$tos  = array(); // Получатели
		$args = NULL; // Аргументы ответа последней комманды

		foreach (explode(',', $to) as $t) {
			if (! ($t = trim($t))) {
				continue;
			}

			$tos[] = $this->formatRCPT($t);
		}

		if (! $tos) {
			throw new SMTPMail_Exception("Нет получателей");
		}

		// Начало сообщения
		$this->command("MAIL", "FROM: <{$from}>");
		$this->read(250);

		foreach ($tos as $t) {
			// Добавим получателя
			$this->command("RCPT", "TO: <{$t}>");
			$this->read(250);
		}

		// Начало передачи данных
		$this->command("DATA");

		// Сервер ждем сообщение
		$this->read(354);

		// Кому
		$this->write("To: {$this->joinReceivers($tos)}" . self::EOL);

		// От
		$this->write("From: {$this->getSubject($from_username)} <$from>" . self::EOL);

		// Тема
		$this->write("Subject: {$this->getSubject($subject)}" . self::EOL);

		// MIME Версия
		$this->write("MIME-Version: 1.0" . self::EOL);

		// Сообщение
		$this->write($this->getMessage() . self::EOL);

		// Конец
		$this->write(self::EOL . '.' . self::EOL);

		// Успешно отправлено
		$this->read(250, $args);

		preg_match("/([0-9A-Z]{4,})$/", $args, $matches);

		return $matches[1];
	}

	public function reset()
	{
		$this->command('RSET');
		$this->read(250);
	}

	/**
	 * Конвертирует расширенное написание мыльников в простой xx@xxx.xx
	 * @param string $rcpt
	 * @return string
	 * @throws SMTPMail_RCPT_Exception
	 */
	protected function formatRCPT($rcpt)
	{
		// Расширенная форма получателя
		if (preg_match("/^(.+)\s<(.+)>$/ui", $rcpt, $m)) {
			$rcpt = $m[2];
		}

		// Если адрес выглядит валидным
		if (preg_match("/^(?:[a-z0-9!#$%&'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&'*+\/=?^_`{|}~-]+)*|\"(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21\x23-\x5b\x5d-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])*\")@(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?|\[(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?|[a-z0-9-]*[a-z0-9]:(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21-\x5a\x53-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])+)\])$/i", $rcpt)) {
			return $rcpt;
		}

		throw new SMTPMail_RCPT_Exception($rcpt);
	}

	/**
	 * Конвертируем массив получателей в строку
	 * @param array $tos
	 * @return string
	 */
	protected function joinReceivers(array $tos)
	{
		return implode(', ', $tos);
	}

	/**
	 * Последняя выполненеая комманда
	 * @var string
	 */
	protected $last_command;

	/**
	 * Отправляем комманду SMTP серверу
	 * @param $command
	 * @param null $args
	 * @return int
	 */
	protected function command($command, $args = NULL)
	{
		// Аргументы
		if ($args) {
			$command .= " $args";
		}

		$this->last_command = $command;

		return $this->write($command . self::EOL);
	}

	/**
	 * Отправка данных SMTP серверу
	 * @param $string
	 * @return int
	 */
	protected function write($string)
	{
		// При записи нужно подключиться к серверу SMTP (Если еще не подключились)
		$this->connect();

		if ($this->debugger) {
			call_user_func($this->debugger, "==> " . trim($string));
		}

		return @fwrite($this->socket, $string);
	}

	/**
	 * Обработка ответа от SMTP сервера
	 * @param $valid_code
	 * @param $args
	 * @throws SMTPMail_Command_Exception
	 */
	protected function read($valid_code, & $args = NULL)
	{
		$line = $this->readLine();

		$code = substr($line, 0, 3);

		if (! $args) {
			$args = substr($line, 4);
		}

		if ($valid_code != $code) {
			throw new SMTPMail_Command_Exception("\n\nПоследняя комманда: {$this->last_command}\n Неправильный ответ. Ожидалось $valid_code. Получил $code.\n\n Описание: $line\n", $code);
		}
	}

	/**
	 * Читаем строку
	 * @return string
	 * @throws SMTPMail_Socket_Exception
	 */
	protected function readLine()
	{
		if (! feof($this->socket)) {
			$line = @fgets($this->socket);

			if ($this->debugger) {
				call_user_func($this->debugger, "<== " . trim($line));
			}

			return rtrim($line, self::EOL);
		}

		throw new SMTPMail_Socket_Exception("Can not read from socket");
	}
}

class SMTPMail_Exception         extends XException {}

class SMTPMail_RCPT_Exception    extends SMTPMail_Exception {}

class SMTPMail_Command_Exception extends SMTPMail_Exception {}

class SMTPMail_Socket_Exception  extends SMTPMail_Exception {}
