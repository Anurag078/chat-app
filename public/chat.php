<?php
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/db.php";


// Default to General room
$roomId = 1;
$stmt = $pdo->prepare("
  SELECT m.message_text, m.timestamp, u.username 
  FROM messages m 
  JOIN users u ON m.user_id = u.id 
  WHERE m.room_id = ?
  ORDER BY m.timestamp ASC
");
$stmt->execute([$roomId]);
$messages = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
  <title>Chat Room</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Arial, sans-serif; background-color: #f1f1f1; }
    .container { display: flex; height: 100vh; }
    .sidebar { width: 20%; background: #343a40; color: #fff; padding: 20px; overflow-y: auto; }
    .sidebar h3 { margin-bottom: 15px; }
    .sidebar ul { list-style: none; }
    .sidebar ul li {
      padding: 10px;
      cursor: pointer;
      border-radius: 5px;
    }
    .sidebar ul li:hover { background-color: #495057; }
    .chat-section { flex: 1; display: flex; flex-direction: column; }
    .chat-header {
      background-color: #007bff;
      color: white;
      padding: 15px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .chat-box {
      flex: 1;
      padding: 20px;
      background: #ffffff;
      overflow-y: auto;
    }
    .chat-box .message {
      margin-bottom: 10px;
      padding: 10px;
      background: #f1f1f1;
      border-radius: 5px;
    }
    .chat-input {
      display: flex;
      padding: 10px;
      background: #eee;
    }
    .chat-input input {
      flex: 1;
      padding: 10px;
      border: 1px solid #ccc;
      border-radius: 5px;
    }
    .chat-input button {
      padding: 10px 20px;
      margin-left: 10px;
      background-color: #007bff;
      color: white;
      border: none;
      border-radius: 5px;
      cursor: pointer;
    }
    .chat-input button:hover { background-color: #0056b3; }
  </style>
</head>
<body>

<div class="container">
  <div class="sidebar">
    <h3>Chat Rooms</h3>
    <ul>
      <li onclick="switchRoom(1)"> General</li>
      <li onclick="switchRoom(2)"> Tech Support</li>
      <li onclick="switchRoom(3)"> Random</li>
    </ul>

    <h3 style="margin-top: 20px;">Active Users</h3>
    <ul id="userList"></ul>
  </div>

  <div class="chat-section">
    <div class="chat-header">
      <span id="currentRoom">Welcome, <?php echo $_SESSION["username"]; ?>! (Room: General)</span>

    
      <a href="logout.php" style="color:white; text-decoration: none;">Logout</a>
    </div>

    <div class="chat-box" id="chatBox">
      <?php foreach ($messages as $msg): ?>
        <div class="message">
          <strong>[<?= htmlspecialchars($msg["username"]) ?>]</strong>
          <?= htmlspecialchars($msg["message_text"]) ?>
          <small style="float:right; color:gray;"><?= $msg["timestamp"] ?></small>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Typing Indicator -->
    <div id="typingIndicator" style="padding: 5px 20px; font-style: italic; color: gray;"></div>

    <!-- Message Input -->
    <div class="chat-input">
      <input type="text" id="messageInput" placeholder="Type your message..." />
      <button onclick="sendMessage()">Send</button>
    </div>
  </div>
</div>

<script>
  const username = "<?php echo $_SESSION['username']; ?>";
  let currentRoomId = 1;
  let typingTimeout;
  let isTabActive = true;
  const socket = new WebSocket("ws://localhost:8080");

  //  Notification permission
  if (Notification.permission !== "granted") {
    Notification.requestPermission().then(permission => {
      console.log("Notification permission:", permission);
    });
  }

  //  Tab visibility tracking
  document.addEventListener("visibilitychange", () => {
    isTabActive = !document.hidden;
  });

  //  Notification sound element
  const sound = new Audio("https://notificationsounds.com/storage/sounds/file-sounds-1155-pristine.mp3");

  socket.onopen = () => {
    console.log(" Connected to WebSocket");
    socket.send(JSON.stringify({ type: "init", user: username }));
  };

  socket.onmessage = event => {
    try {
      const data = JSON.parse(event.data);
      if (data.type === "user_list") {
        const userList = data.users.map(user => {
          if (user !== username) {
            return `<li onclick="sendPrivateMessage('${user}')">${user}</li>`;
          } else {
            return `<li style="color: gray;">${user} (You)</li>`;
          }
        }).join("");
        document.getElementById("userList").innerHTML = userList;
      } else if (data.type === "typing") {
        document.getElementById("typingIndicator").innerText = `${data.user} is typing...`;
        clearTimeout(typingTimeout);
        typingTimeout = setTimeout(() => {
          document.getElementById("typingIndicator").innerText = "";
        }, 2000);
      } else if (data.type === "private") {
        appendMessage(`[Private] ${data.message}`);
      } else {
        appendMessage(event.data);
      }
    } catch {
      appendMessage(event.data);
    }
  };

  function appendMessage(text) {
    const div = document.createElement("div");
    div.className = "message";
    div.innerText = text;
    document.getElementById("chatBox").appendChild(div);
    document.getElementById("chatBox").scrollTop = document.getElementById("chatBox").scrollHeight;

    //  Show notification and play sound if tab is not active
    if (!isTabActive && Notification.permission === "granted") {
      const messageUser = text.match(/\[(.*?)\]/)?.[1] || "New Message";
      const messageBody = text.split("]: ")[1] || text;

      new Notification(`💬 ${messageUser}`, {
        body: messageBody,
        icon: "https://cdn-icons-png.flaticon.com/512/733/733585.png"
      });

      sound.play().catch(err => console.warn("Sound blocked:", err));
    }
  }

  function sendMessage() {
    const input = document.getElementById("messageInput");
    const msg = input.value.trim();
    if (msg !== "") {
      socket.send(JSON.stringify({
        type: "chat_message",
        user: username,
        room_id: currentRoomId,
        message: msg
      }));
      input.value = "";
    }
  }

  function sendPrivateMessage(toUser) {
    const msg = prompt(`Send private message to ${toUser}:`);
    if (msg) {
      socket.send(JSON.stringify({
        type: "private_message",
        user: username,
        to_user: toUser,
        message: msg
      }));
    }
  }

  function switchRoom(roomId) {
  currentRoomId = roomId;
  document.getElementById("chatBox").innerHTML = "";

  const roomNames = {
    1: "General",
    2: "Tech Support",
    3: "Random"
  };

  document.getElementById("currentRoom").innerText =
    `Welcome, ${username}! (Room: ${roomNames[roomId] || "Unknown"})`;

  socket.send(JSON.stringify({
    type: "switch_room",
    user: username,
    room_id: roomId
  }));
}


  document.getElementById("messageInput").addEventListener("input", () => {
    socket.send(JSON.stringify({
      type: "typing",
      user: username
    }));
  });
</script>


</body>
</html>
