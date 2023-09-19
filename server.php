<?php
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

require __DIR__ . '/vendor/autoload.php';

class WebSocketsServer implements MessageComponentInterface {
    protected $clients;
	protected $mysqli;
    
    public function __construct() {
        $this->clients = new \SplObjectStorage;
		$this->mysqli = new mysqli('localhost', '', '', '');
    }
	public function __destruct() {
		$this->mysqli->close();
	}

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }
    
    public function onMessage(ConnectionInterface $from, $msg) {
		// the very first message from the client is his ID
		if(!isset($from->UserID)) {
			$from->UserID = $msg;
		}

		// lamp broadcast
		if($from->UserID == "lamp" && $msg != "lamp") {
			foreach ($this->clients as $client) {
				if ($from !== $client) {
					$client->send($msg);
				}
			}
		}
    }
    
    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }
    
    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }
	
	private function getCustomerInfo($phone) {
		$title = $phone;
		$stmt = $this->mysqli->prepare("SELECT title FROM customers WHERE `phone` = ? LIMIT 1;");
		$stmt->bind_param("s", $phone);
		$stmt->execute();
		$res = $stmt->get_result();
		if($res) {
			if($row = $res->fetch_array(MYSQLI_ASSOC)) {
				$title = $row['title'];
			}
			$res->close();
		}
		$stmt->close();
		return $title;
	}
	
	public function doStuff() {
		
		$calls = __DIR__ . "/calls.list";
		
		if (file_exists($calls)) {
			$lines = file($calls, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			$parsed = array();
			foreach ($lines as $line) {
				list($from, $to ) = explode(';',$line);
				$parsed[$to] = $this->getCustomerInfo($from);
			}
			
			foreach ($this->clients as $client) {
				if(isset($client->UserID)) {
					// $client->send($parsed[$client->UserID]);
					
					foreach($parsed as $toNum => $fromNum) {
						if($client->UserID == $toNum) {
							$client->send($fromNum);
						}
					}
					
				}
			}
			
			unlink($calls);
		}
	
	}
}

$handler = new WebSocketsServer();

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            $handler
        )
    ),
    7777
);

$server->loop->addPeriodicTimer(0.1, function () use ($handler) {
	$handler->doStuff();
});

$server->run();
