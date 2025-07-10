<?php
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once __DIR__ . '/../vendor/autoload.php';

class ChatServer implements MessageComponentInterface {
    protected $clients;
    private $pdo;
    private $usernames = [];

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        $this->pdo = new PDO("mysql:host=localhost;dbname=chat_app", "root", "1234");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo " WebSocket server started\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->room_id = 1;
        echo "➕ New connection: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!$data || !isset($data['type'])) return;

        if ($data['type'] === "init") {
            $this->usernames[$from->resourceId] = $data['user'];
            $from->room_id = 1;
            $this->broadcastUserList(1);
        }

        if ($data['type'] === "switch_room") {
            $from->room_id = $data['room_id'];
            echo "🔄 User {$data['user']} switched to room {$data['room_id']}\n";
            $this->broadcastUserList($data['room_id']);

            $stmt = $this->pdo->prepare("
                SELECT m.message_text, m.timestamp, u.username
                FROM messages m
                JOIN users u ON m.user_id = u.id
                WHERE m.room_id = ?
                ORDER BY m.timestamp ASC
            ");
            $stmt->execute([$data['room_id']]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($history as $msg) {
                $formatted = "[{$msg['username']}] {$msg['message_text']} ({$msg['timestamp']})";
                $from->send($formatted);
            }
        }

        if ($data['type'] === "chat_message") {
            $roomId = $data['room_id'];
            $message = "[{$data['user']}]: {$data['message']}";

            foreach ($this->clients as $client) {
                if ($client->room_id == $roomId) {
                    $client->send($message);
                }
            }

            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$data['user']]);
            $user = $stmt->fetch();

            if ($user) {
                $stmt = $this->pdo->prepare("INSERT INTO messages (room_id, user_id, message_text, timestamp) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$roomId, $user['id'], $data['message']]);
            }
        }

        if ($data['type'] === "typing") {
            foreach ($this->clients as $client) {
                if ($client !== $from && $client->room_id == $from->room_id) {
                    $client->send(json_encode([
                        'type' => 'typing',
                        'user' => $data['user']
                    ]));
                }
            }
        }

        if ($data['type'] === "private_message") {
            $toUser = $data['to_user'];
            $msg = "[Private] {$data['user']}: {$data['message']}";

            foreach ($this->clients as $client) {
                $clientName = $this->usernames[$client->resourceId] ?? '';
                if ($clientName === $toUser || $client === $from) {
                    $client->send(json_encode([
                        'type' => 'private',
                        'from' => $data['user'],
                        'to' => $toUser,
                        'message' => $msg
                    ]));
                }
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        unset($this->usernames[$conn->resourceId]);
        $this->broadcastUserList($conn->room_id ?? 1);
        echo " Connection {$conn->resourceId} closed\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo " Error: {$e->getMessage()}\n";
        $conn->close();
    }

    private function broadcastUserList($roomId) {
        $usersInRoom = [];

        foreach ($this->clients as $client) {
            if (($client->room_id ?? 1) == $roomId && isset($this->usernames[$client->resourceId])) {
                $usersInRoom[] = $this->usernames[$client->resourceId];
            }
        }

        $payload = json_encode([
            'type' => 'user_list',
            'users' => $usersInRoom
        ]);

        foreach ($this->clients as $client) {
            if (($client->room_id ?? 1) == $roomId) {
                $client->send($payload);
            }
        }
    }
}
