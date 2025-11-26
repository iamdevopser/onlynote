<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructor Dashboard - OnlyNote LMS</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }
        .header {
            background: #764ba2;
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #333; margin-bottom: 1rem; }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }
        .stat-card {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
        }
        .stat-card h3 { font-size: 2rem; margin-bottom: 0.5rem; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎓 OnlyNote LMS - Instructor Dashboard</h1>
        <div>
            <span>Welcome, {{ Auth::user()->name }}</span>
            <form action="{{ route('instructor.logout') }}" method="POST" style="display: inline;">
                @csrf
                <button type="submit" style="background: rgba(255,255,255,0.2); border: 1px solid white; color: white; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer; margin-left: 1rem;">Logout</button>
            </form>
        </div>
    </div>
    <div class="container">
        <h2>My Dashboard</h2>
        <div class="stats">
            <div class="stat-card">
                <h3>0</h3>
                <p>My Courses</p>
            </div>
            <div class="stat-card">
                <h3>0</h3>
                <p>Total Students</p>
            </div>
            <div class="stat-card">
                <h3>$0</h3>
                <p>Total Earnings</p>
            </div>
        </div>
        <p style="margin-top: 2rem; color: #666;">Instructor panel is ready. You can now create and manage your courses.</p>
    </div>
</body>
</html>

