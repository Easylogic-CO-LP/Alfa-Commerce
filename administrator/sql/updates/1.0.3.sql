--
-- Table structure for table `#__alfa_items_medias`
--


CREATE TABLE IF NOT EXISTS `#__alfa_items_media` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `type` varchar(20) DEFAULT NULL,
  `path` varchar(250) NOT NULL,
  `thumbnail` varchar(400) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `alt` varchar(75) DEFAULT NULL,
  `ordering` int(11) NOT NULL DEFAULT 0,
  `origin` varchar(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;