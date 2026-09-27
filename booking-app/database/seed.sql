-- Starter data: services and opening hours. Prices are placeholders — edit them in Admin → Services.
-- install.php only runs this when the services table is empty.

INSERT INTO services (slug, name, tagline, description, icon, price_from, duration_minutes, meeting_types, sort_order) VALUES
('website', 'Website Design & Build', 'Make me a website',
 'A fast, mobile-first website that looks like your brand and turns visitors into customers. WordPress or custom-built, with contact forms, maps, bookings and everything set up for Google.',
 'code', 450.00, 30, 'online,phone,in_person', 1),
('web-app', 'Web App Development', 'Develop me a web app',
 'Booking systems, client portals, dashboards and online tools built around the way your business works: secure, easy to use and ready to grow with you.',
 'app', 1500.00, 45, 'online,phone,in_person', 2),
('seo', 'SEO & Google Visibility', 'Get me found on Google',
 'Technical SEO audit, keyword research, on-page optimization and a Google Business Profile that brings local customers to your door.',
 'search', 250.00, 30, 'online,phone', 3),
('content', 'Content Creation', 'Fill my social media',
 'Posts, reels, blog articles and website copy that sound like you and keep your audience engaged, planned in a simple monthly content calendar.',
 'megaphone', 300.00, 30, 'online,phone,in_person', 4),
('graphic-design', 'Graphic Design & Branding', 'Design my brand',
 'Logos, brand kits, business cards, flyers, menus and social media templates that make your business instantly recognizable.',
 'pen-tool', 200.00, 30, 'online,phone,in_person', 5),
('photography', 'Photography', 'Photograph my business',
 'Product, team, food and interior photography that shows your business at its best, edited and delivered ready for web, social and print.',
 'camera', 180.00, 90, 'in_person', 6),
('videography', 'Videography', 'Film my story',
 'Promo videos, reels and short-form content: planned, shot, edited and optimized for Instagram, TikTok, YouTube and your website.',
 'video', 350.00, 120, 'in_person', 7),
('launch-pack', 'Small Business Launch Pack', 'Get my business online',
 'Everything you need to start strong: a 5-page website, Google Business Profile setup, a logo refresh and a photo session. One plan, one partner, one price.',
 'star', 1200.00, 45, 'online,phone,in_person', 8);

INSERT INTO working_hours (day_of_week, is_open, open_time, close_time) VALUES
(1, 1, '09:00:00', '17:00:00'),
(2, 1, '09:00:00', '17:00:00'),
(3, 1, '09:00:00', '17:00:00'),
(4, 1, '09:00:00', '17:00:00'),
(5, 1, '09:00:00', '17:00:00'),
(6, 1, '10:00:00', '14:00:00'),
(7, 0, '10:00:00', '14:00:00');
