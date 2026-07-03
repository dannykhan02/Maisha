<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Maisha Password</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');

        body {
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f5f7fb;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 560px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        .card {
            background-color: #ffffff;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            border: 1px solid #e9eef2;
        }
        .header {
            background-color: #0D9E75;
            padding: 28px 24px;
            text-align: center;
        }
        .header h1 {
            color: #ffffff;
            font-size: 24px;
            font-weight: 600;
            margin: 0;
            letter-spacing: -0.3px;
        }
        .content {
            padding: 32px 28px;
        }
        .content p {
            color: #2d3a4b;
            font-size: 16px;
            line-height: 1.5;
            margin: 0 0 20px;
        }
        .button {
            display: inline-block;
            background-color: #0D9E75;
            color: #ffffff;
            font-weight: 600;
            font-size: 15px;
            padding: 12px 28px;
            border-radius: 40px;
            text-decoration: none;
            margin: 16px 0 8px;
            transition: background 0.2s;
            border: none;
            cursor: pointer;
        }
        .button:hover {
            background-color: #0a7d5d;
        }
        .footer {
            background-color: #f8fafc;
            padding: 20px 24px;
            text-align: center;
            border-top: 1px solid #eef2f6;
            font-size: 12px;
            color: #7c8b9c;
        }
        .footer a {
            color: #0D9E75;
            text-decoration: none;
        }
        @media (max-width: 600px) {
            .container { padding: 16px; }
            .content { padding: 24px 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1>🔐 Maisha</h1>
            </div>
            <div class="content">
                <p>Hi <strong>{{ $name ?? 'there' }}</strong>,</p>
                <p>We received a request to reset the password for your Maisha account. Click the button below to choose a new password. This link will expire in 60 minutes.</p>
                <p style="text-align: center;">
                    <a href="{{ $resetUrl }}" class="button">Reset my password</a>
                </p>
                <p>If you did not request a password reset, please ignore this email. Your password will not be changed.</p>
                <p>If the button doesn’t work, copy and paste this link into your browser:<br>
                <a href="{{ $resetUrl }}" style="color:#0D9E75; word-break:break-all;">{{ $resetUrl }}</a></p>
                <p>Stay healthy,<br>The Maisha Team</p>
            </div>
            <div class="footer">
                <p>© {{ date('Y') }} Maisha. All rights reserved.</p>
                <p>You received this email because you (or someone else) requested a password reset for your account.</p>
            </div>
        </div>
    </div>
</body>
</html>