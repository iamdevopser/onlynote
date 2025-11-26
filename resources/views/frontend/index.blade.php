<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnlyNote LMS - Learning Management System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .nav a {
            color: white;
            text-decoration: none;
            margin-left: 1rem;
        }
        .hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4rem 2rem;
            text-align: center;
        }
        .hero h1 { font-size: 3rem; margin-bottom: 1rem; }
        .hero p { font-size: 1.2rem; margin-bottom: 2rem; }
        .btn {
            display: inline-block;
            padding: 1rem 2rem;
            background: white;
            color: #667eea;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin: 0 0.5rem;
        }
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 2rem;
        }
        .courses {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }
        .course-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .course-card img {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }
        .course-card-content {
            padding: 1.5rem;
        }
        .course-card h3 { margin-bottom: 0.5rem; color: #333; }
        .course-card p { color: #666; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎓 OnlyNote LMS</h1>
        <nav class="nav">
            <a href="{{ route('home') }}">Home</a>
            <a href="{{ route('login') }}">Login</a>
            <a href="{{ route('register') }}">Register</a>
        </nav>
    </div>
    
    <div class="hero">
        <h1>Welcome to OnlyNote LMS</h1>
        <p>Learn, Teach, and Grow with Our Learning Management System</p>
        <a href="{{ route('register') }}" class="btn">Get Started</a>
        <a href="{{ route('login') }}" class="btn" style="background: rgba(255,255,255,0.2); color: white;">Login</a>
    </div>
    
    <div class="container">
        <h2>Featured Courses</h2>
        <div class="courses">
            <div class="course-card">
                <div style="background: #667eea; height: 200px; display: flex; align-items: center; justify-content: center; color: white; font-size: 3rem;">📚</div>
                <div class="course-card-content">
                    <h3>No Courses Yet</h3>
                    <p>Courses will appear here once they are created by instructors.</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

