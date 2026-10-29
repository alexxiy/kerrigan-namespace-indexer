--
-- Table structure for table `checkpoints`
--

DROP TABLE IF EXISTS `checkpoints`;
-- `idx` is the position of the root in the anchor contract (its `rootAt` index): the resume cursor for
-- checkpoint discovery, and what makes a rollback detectable (an index can later hold a
-- different root).
--
-- `block_id` is the Kerrigan height the root covers. commitBatch enforces it strictly
-- increasing, so it is unique and monotonic, which is why it orders this table.
CREATE TABLE `checkpoints` (
  `idx` int unsigned NOT NULL,
  `block_id` int unsigned NOT NULL,
  `smt_root` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`idx`),
  UNIQUE KEY `block_id` (`block_id` DESC) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Table structure for table `domains`
--

DROP TABLE IF EXISTS `domains`;
CREATE TABLE `domains` (
  `domain_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_block_id` int unsigned NOT NULL,
  `updated_block_id` int unsigned NOT NULL,
  `created_ts` int unsigned NOT NULL,
  `updated_ts` int unsigned NOT NULL,
  `nonce` int unsigned NOT NULL,
  PRIMARY KEY (`domain_name`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Table structure for table `domains_history`
--

DROP TABLE IF EXISTS `domains_history`;
CREATE TABLE `domains_history` (
  `domain_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `domain_block_id` int unsigned NOT NULL,
  `domain_ts` int unsigned NOT NULL,
  `target_address` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_pubkey` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `domain_tx` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `op` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nonce` int unsigned NOT NULL,
  `price` bigint unsigned NOT NULL,
  PRIMARY KEY (`domain_name`,`domain_block_id`,`nonce`) USING BTREE,
  KEY `target_address` (`target_address`) USING BTREE,
  KEY `owner_pubkey` (`owner_pubkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Table structure for table `marketplace`
--

DROP TABLE IF EXISTS `marketplace`;
CREATE TABLE `marketplace` (
  `domain_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `price` bigint unsigned NOT NULL,
  `block_id` int unsigned NOT NULL,
  `nonce` int unsigned NOT NULL,
  PRIMARY KEY (`domain_name`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Table structure for table `params`
--

DROP TABLE IF EXISTS `params`;
CREATE TABLE `params` (
  `param` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `value` int unsigned NOT NULL,
  PRIMARY KEY (`param`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Table structure for table `rpc_list`
--

DROP TABLE IF EXISTS `rpc_list`;
CREATE TABLE `rpc_list` (
  `chain_id` mediumint unsigned NOT NULL,
  `rpc_url` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `issue_ts` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`chain_id`,`rpc_url`),
  KEY `idx_chain_issue` (`chain_id`,`issue_ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
