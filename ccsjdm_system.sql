-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 08, 2026 at 11:17 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ccsjdm_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `action` varchar(255) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `username`, `action`, `timestamp`) VALUES
(1, 'cbxcbx', 'User logged into the system', '2026-10-08 03:59:32'),
(2, 'cbxcbx', 'User logged out', '2026-10-08 03:59:49'),
(3, 'ff', 'User logged into the system', '2026-10-08 04:14:56'),
(4, 'ff', 'User logged out', '2026-10-08 04:15:17'),
(5, '2024-17-0206', 'User logged into the system', '2026-10-08 04:58:35'),
(6, '2024-17-0206', 'Added schedule: Comprog', '2026-10-08 05:00:19'),
(7, '2024-17-0206', 'Deleted schedule ID: 1', '2026-10-08 05:00:54'),
(8, '2024-17-0206', 'Added schedule: Comprog', '2026-10-08 05:01:32'),
(9, '2024-17-0206', 'User logged out', '2026-10-08 05:09:03'),
(10, '2024-17-0206', 'User logged into the system', '2026-10-08 05:09:25'),
(11, '2024-17-0206', 'User logged out', '2026-10-08 05:13:02'),
(12, '2024-17-0206', 'User logged into the system', '2026-10-08 05:13:25'),
(13, '2024-17-0206', 'User logged out', '2026-10-08 05:21:13'),
(14, 'rgteg', 'User logged into the system', '2026-10-08 05:21:19'),
(15, 'rgteg', 'Deleted schedule ID: 2', '2026-10-08 05:21:24'),
(16, 'rgteg', 'Deleted schedule ID: 2', '2026-10-08 05:21:30'),
(17, 'rgteg', 'Initiated System Back-up', '2026-10-08 05:31:25'),
(18, 'rgteg', 'User logged out', '2026-10-08 07:09:24'),
(19, '2024-17-0206', 'User logged into the system', '2026-10-08 07:12:23'),
(20, '2024-17-0206', 'Initiated System Back-up', '2026-10-08 07:15:15'),
(21, '2024-17-0206', 'User logged out', '2026-10-08 07:20:07'),
(22, 'aADFA', 'User logged into the system', '2026-10-08 07:26:12'),
(23, 'aADFA', 'User logged out', '2026-10-08 07:27:29'),
(24, 'FFS', 'User logged into the system', '2026-10-08 07:28:19'),
(25, 'FFS', 'Added schedule: Comprog', '2026-10-08 07:35:06'),
(26, 'FFS', 'User logged out', '2026-10-08 07:36:43'),
(27, 'SGSG', 'User logged into the system', '2026-10-08 09:08:06'),
(28, 'SGSG', 'Deleted schedule ID: 3', '2026-10-08 09:08:40'),
(29, 'SGSG', 'User logged out', '2026-10-08 09:10:14'),
(30, 'VZ', 'User logged into the system', '2026-10-08 09:10:18'),
(31, 'VZ', 'User logged out', '2026-10-08 09:12:15'),
(32, 'ACDSV', 'User logged into the system', '2026-10-08 09:12:50');

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
--

CREATE TABLE `schedules` (
  `id` int(11) NOT NULL,
  `prof` varchar(100) NOT NULL,
  `subject` varchar(100) NOT NULL,
  `time_slot` varchar(100) NOT NULL,
  `day_slot` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
