# Train Ticket Finder Bot

This project is a **Telegram bot** designed to manage train ticket notifications. It allows users to input origin, destination, and departure date, then fetches available train tickets using the Railway API.

## Features

- **Admin Notifications**: Notify admins when new train tickets are available.
- **User-Friendly Interface**: Users can easily input search parameters via Telegram.
- **SQLite Integration**: Stores user data and search queries.
- **Dynamic Headers**: Makes API requests with dynamic headers for improved compatibility.

## Technologies Used

- **PHP**: Core language for bot logic.
- **SQLite**: Lightweight database to store user data and search queries.
- **Telegram Bot API**: Direct interaction with Telegram's API without additional libraries.
- **Railway API**: Fetches train ticket data.

## Installation

1. **Clone the repository**:
   ```bash
   git clone https://github.com/sma-abyar/train-ticket-finder.git
   cd train-ticket-finder
   ```

2. **Set up your environment file**:
   Create a `.env` file in the root directory:
   ```env
   BOT_TOKEN=your_telegram_bot_token
   ADMIN_CHAT_ID=your_admin_chat_id
   URL=your_api_url_here
   DB_PATH=path_to_your_database_here
   HEADERS=your_json_header
   ```

3. **Install dependencies**:
   No external libraries are required as the bot interacts directly with Telegram and Railway APIs.

4. **Run the bot**:
   ```bash
   php bot.php
   ```

## Usage

1. **Start the bot**:
   Users can start interacting with the bot via Telegram by sending a message to the bot.

2. **Admin notifications**:
   The admin will be notified when tickets matching the users' queries become available.

3. **Search for train tickets**:
   Users can enter their search parameters to get real-time train ticket availability.

## Project Structure

```
train-ticket-finder/
├── bot.php            # Main bot logic
├── .env               # Environment variables
├── users.db           # SQLite database
└── README.md          # Project documentation
```

## Contributing

Feel free to submit issues or pull requests if you'd like to contribute to this project.

## License

This project is licensed under the MIT License. See the `LICENSE` file for details.

--- 

Let me know if you'd like to add or adjust anything!
