<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PESO Bansud - Official Home Page</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/index.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
</head>

<body>
    <!-- Navigation Overlay -->
    <div class="nav-overlay" id="navOverlay"></div>

    <header class="landing-header">
        <div class="container">
            <div class="logo-section">
                <div class="logo-circle">
                    <img src="/images/PesoLogo.jpg" alt="PESO Bansud Logo" class="logo-image">
                </div>
                <div class="logo-text">
                    <h1>PESO Bansud</h1>
                    <p>Public Employment Service Office</p>
                </div>
            </div>

            <!-- Hamburger Toggle Button -->
            <button class="sidebar-toggle" id="sidebarToggle">
                <div class="hamburger-icon">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </button>

            <nav class="main-nav" id="mainNav">
                <ul>
                    <li><a href="#home">Home</a></li>
                    <li><a href="#about">About Us</a></li>
                    <li><a href="#services">Services</a></li>
                    <li><a href="#contact">Contact Us</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <section id="home" class="home-section-landing">
        <div class="carousel-container">
            <div class="carousel-slide active" style="background-image: url('/images/Pic4.png')"></div>
            <div class="carousel-slide" style="background-image: url('/images/Pic3.png')"></div>
            <div class="carousel-slide" style="background-image: url('/images/Pic2.png')"></div>
            <div class="carousel-slide" style="background-image: url('/images/Pic1.png')"></div>
            <div class="carousel-slide" style="background-image: url('/images/background1.png')"></div>
            <div class="carousel-slide" style="background-image: url('/images/background2.png')"></div>

            <!-- Carousel Navigation -->
            <div class="carousel-nav">
                <button class="carousel-btn prev-btn" onclick="changeSlide(1)">&#10094;</button>
                <button class="carousel-btn next-btn" onclick="changeSlide(-1)">&#10095;</button>
            </div>

            <!-- Carousel Indicators -->
            <div class="carousel-indicators">
                <span class="indicator active" onclick="currentSlide(1)"></span>
                <span class="indicator" onclick="currentSlide(2)"></span>
                <span class="indicator" onclick="currentSlide(3)"></span>
                <span class="indicator" onclick="currentSlide(4)"></span>
                <span class="indicator" onclick="currentSlide(5)"></span>
                <span class="indicator" onclick="currentSlide(6)"></span>
            </div>
        </div>

        <div class="home-content-landing">
            <h1>Jobmatch: Labor Force Management System of PESO in Bansud, Oriental Mindoro </h1>
            <p>Connecting job seekers with opportunities and supporting local businesses.</p>

            <a href="auth/login.php" class="btn-primary-landing">Login Now</a>
            <a href="auth/register.php" class="btn-secondary-landing">Register Now</a>
        </div>
    </section>

    <section id="about" class="info-section">
        <div class="container">
            <h2>About PESO Bansud</h2>
            <p>The Public Employment Service Office (PESO) in Bansud, Oriental Mindoro, is a non-fee charging multi-employment service facility or entity established in all local government units (LGUs) in coordination with the Department of Labor and Employment (DOLE).</p>
            <p>Our mission is to provide timely and relevant employment facilitation services to our constituents, ensuring a productive and skilled workforce that contributes to the economic development of our municipality.</p>
        </div>
    </section>

    <section id="services" class="info-section bg-light">
        <div class="container">
            <h2>Our Services</h2>
            <div class="services-grid">
                <div class="service-item">
                    <h3>Job Matching & Referral</h3>
                    <p>Connecting qualified job seekers with suitable employment opportunities from various industries.</p>
                </div>
                <div class="service-item">
                    <h3>Career Counseling</h3>
                    <p>Providing guidance and advice to individuals on career paths, skill development, and job readiness.</p>
                </div>
                <div class="service-item">
                    <h3>Labor Market Information</h3>
                    <p>Disseminating up-to-date information on employment trends, job vacancies, and in-demand skills.</p>
                </div>
                <div class="service-item">
                    <h3>Special Recruitment Activities</h3>
                    <p>Organizing job fairs and special recruitment programs to bring employers and job seekers together.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="contact" class="info-section">
        <div class="container">
            <h2>Contact Us</h2>
            <p>For inquiries and assistance, please reach out to us:</p>
            <p><i class="fas fa-map-marker-alt"></i> Municipal Hall, Bansud, Oriental Mindoro</p>
            <p><i class="fas fa-envelope"></i> --------------</p>
            <p><i class="fas fa-phone"></i> --------------</p>
            <p>Office Hours: Monday - Friday, 8:00 AM - 5:00 PM</p>
        </div>
    </section>

    <footer class="landing-footer">
        <div class="container">

        </div>
    </footer>

    <script>
        let currentSlideIndex = 0;
        const slides = document.querySelectorAll('.carousel-slide');
        const indicators = document.querySelectorAll('.indicator');

        function showSlide(index) {
            // Hide all slides
            slides.forEach(slide => slide.classList.remove('active'));
            indicators.forEach(indicator => indicator.classList.remove('active'));

            // Show the selected slide
            slides[index].classList.add('active');
            indicators[index].classList.add('active');
        }

        function changeSlide(direction) {
            currentSlideIndex += direction;

            if (currentSlideIndex >= slides.length) {
                currentSlideIndex = 0;
            } else if (currentSlideIndex < 0) {
                currentSlideIndex = slides.length - 1;
            }

            showSlide(currentSlideIndex);
        }

        function currentSlide(index) {
            currentSlideIndex = index - 1;
            showSlide(currentSlideIndex);
        }

        // Auto-play carousel
        setInterval(() => {
            changeSlide(1);
        }, 5000); // Change slide every 5 seconds
    </script>

    <script>
        // Hamburger Navigation Functionality
        const sidebarToggle = document.getElementById('sidebarToggle');
        const mainNav = document.getElementById('mainNav');
        const navOverlay = document.getElementById('navOverlay');

        function toggleNav() {
            sidebarToggle.classList.toggle('active');
            mainNav.classList.toggle('nav-open');
            navOverlay.classList.toggle('active');
            document.body.classList.toggle('nav-mobile-open');
        }

        function closeNav() {
            sidebarToggle.classList.remove('active');
            mainNav.classList.remove('nav-open');
            navOverlay.classList.remove('active');
            document.body.classList.remove('nav-mobile-open');
        }

        // Event listeners
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', toggleNav);
        }

        if (navOverlay) {
            navOverlay.addEventListener('click', closeNav);
        }

        // Close nav when clicking on nav links (mobile only)
        const navLinks = document.querySelectorAll('.main-nav a');
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 900) {
                    closeNav();
                }
            });
        });

        // Handle window resize
        window.addEventListener('resize', () => {
            if (window.innerWidth > 900) {
                closeNav();
            }
        });

        // Close with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && mainNav.classList.contains('nav-open')) {
                closeNav();
            }
        });
    </script>

</body>

</html>