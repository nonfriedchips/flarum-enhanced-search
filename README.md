# Flarum Enhanced Search

[![CI](https://github.com/nonfriedchips/flarum-enhanced-search/actions/workflows/ci.yml/badge.svg)](https://github.com/nonfriedchips/flarum-enhanced-search/actions/workflows/ci.yml)
[![Latest Release](https://img.shields.io/github/v/release/nonfriedchips/flarum-enhanced-search)](https://github.com/nonfriedchips/flarum-enhanced-search/releases/latest)

面向 Flarum 1.8 + Oracle MySQL/MariaDB 的本地混合搜索扩展。它保留 Flarum 的搜索语法、权限范围、显式排序和 `mostRelevantPost` 行为，同时补上中文检索、子串/前缀匹配与 1～2 次错字容忍。

## 检索流程

1. 对查询做 Unicode NFKC、大小写、HTML 实体、标点和空白规范化。
2. 先探测 Flarum 原生 `MATCH ... AGAINST`；结果达到阈值时直接使用核心 Gambit，保留完整 SQL 分页。
3. 原生结果稀少且派生索引健康时，通过数据库对应的 bigram FULLTEXT 后端召回有限候选：Oracle MySQL 使用原生 `ngram` parser，MariaDB 使用应用层编码的 Unicode bigram token；两者都会为短词生成有上限的相邻交换变体，补齐“零共同 bigram”的颠倒输入。
4. 对候选执行 Unicode Optimal String Alignment 编辑距离重排，支持增、删、改和相邻字符颠倒。
5. 增强模式按“精确标题 > 标题前缀/子串 > 模糊标题 > 原生正文 > 模糊正文”排序；时间和 ID 只用于稳定打破平局。

模糊计算只发生在有限候选集上，派生表查询始终使用 FULLTEXT 索引而不是前导通配符扫描。派生 ngram 召回在后端硬性要求至少 2 个字符；单字符讨论查询只走 Flarum 原生搜索，用户搜索仍可使用可索引的用户名前缀。纯数字不启用错字容忍，短词默认只做精确、前缀和子串匹配。

常规原生讨论结果使用 Flarum 的完整分页；进入增强模式后，结果窗口受“候选上限”设置约束（默认 200、硬上限 300），这是控制 PHP 编辑距离 CPU 成本的硬边界。

## 安全与兼容

- 标题候选从当前 Flarum 讨论查询克隆，因此会继承 `tag:`、`author:`、`is:` 等 Gambit 条件。
- 正文候选经过 `Post::whereVisibleTo($actor)`，最终 SQL 还会再次核对帖子可见性及所属讨论；隐藏、未批准或私密内容不会直接作为候选或摘要返回。
- 所有输入均使用绑定参数；用户名前缀的 LIKE 模式额外转义 `%`、`_` 和转义符本身。
- 显式选择“最新”等排序时，Flarum 的用户排序继续生效；增强模式仍只在候选窗口内排序。
- 最长查询为 64 个 Unicode 字符、最多 8 个词、编辑距离硬上限为 2；超过 24 个字符的单词不做编辑距离扩展。
- 每篇帖子最多索引前 12,000 个 Unicode 字符。

本版本明确支持 Flarum `^1.8`，数据库支持如下：

- Oracle MySQL 8：继续使用内置 `ngram` parser，并要求 `ngram_token_size=2`。该参数是启动期只读配置；迁移会校验实际值，不匹配时停止。
- MariaDB：正式测试 10.11 和 11.8，建议使用仍在维护的 10.11 或更高版本。兼容代码最低接受 10.0.5，但已停止维护的旧分支仅属 best-effort。MariaDB 路径把每个词内 Unicode bigram 无损编码成固定 16 字符 ASCII token，再使用标准 InnoDB FULLTEXT；不需要 Mroonga、MySQL `ngram` parser 或修改默认 `innodb_ft_min_token_size`。迁移会验证服务器的 FULLTEXT token 长度范围确实包含 16。

MariaDB 的编码列会增加派生表和 FULLTEXT 索引的磁盘占用；超大型论坛应先在副本上评估启用时间与容量。若服务器使用自定义 FULLTEXT stopword 表，不要加入以 `bgrm` 开头的内部 token。Flarum 2.x 的搜索 API 已重构，仍不在本版本兼容范围内。

现有 Oracle MySQL 安装升级时会继续使用原表和原索引，无需回填。如果把一个已经安装本扩展的站点从 MySQL 整库迁移到 MariaDB（或反向迁移），派生表不会自动转换；数据库切换完成后应在维护窗口重置本扩展迁移并重建派生索引：

```bash
php -d error_reporting=22527 flarum migrate:reset --extension=nonfriedchips-enhanced-search
php -d error_reporting=22527 flarum migrate
php -d error_reporting=22527 flarum cache:clear
```

### Scout 冲突

Flarum 1.x 每个 Searcher 只有一个 full-text Gambit 槽位。本扩展与 `clarkwinkelmann/flarum-ext-scout` 同时启用时无法叠加；本扩展会在管理后台显示错误并记录日志，而且两个索引器都会产生额外工作。请只启用其中一个。对于超大型论坛，建议改用 Scout + Meilisearch，并禁用本扩展。

## 安装与启用

在扩展发布到 Packagist 前，可直接将 GitHub 仓库配置为 Composer VCS 源：

```bash
composer config repositories.enhanced-search vcs https://github.com/nonfriedchips/flarum-enhanced-search
composer require nonfriedchips/flarum-enhanced-search:^1.0
```

正式启用会同步格式化全部历史正文并创建 FULLTEXT 索引；迁移期间增量监听器尚未运行，因此应先备份数据库，并在只读/停写维护窗口执行：

```bash
php -d error_reporting=22527 flarum extension:enable nonfriedchips-enhanced-search
php -d error_reporting=22527 flarum cache:clear
```

迁移会从已有讨论、评论和用户建立完整初始索引，不需要紧接着再执行 `reindex`。之后发帖、编辑、重命名和删除事件会同步更新索引。同步写入失败时扩展会把索引标为“脏”并自动停用模糊召回，避免继续使用不完整数据。

Flarum 在禁用扩展期间不会运行索引监听器，因此本扩展在每次禁用时都会主动标记索引为脏。重新启用后先运行 `enhanced-search:reindex`；重建成功会清除标记并恢复模糊召回。管理后台和日志都会提示这一状态。

管理后台可配置：

- 是否启用错字容忍；
- 是否召回帖子正文；
- 允许 1/2 个错字的最短词长；
- 触发增强召回的原生结果阈值；
- 标题/正文合计的模糊候选上限（硬上限 300）；
- 顶栏搜索建议的最短输入长度（后端最小值为 2）。

修改搜索建议长度后需要清理 Flarum 缓存。

## 运维

在格式化规则变化、批量导入数据或怀疑索引不同步后执行：

```bash
php -d error_reporting=22527 flarum enhanced-search:reindex --chunk=200
```

该命令只删除并重建 `enhanced_search_documents` 派生索引，不会修改讨论、帖子或用户。它使用完整事务保证失败回滚；若重建期间没有实时索引写入失败，成功后才会清除索引脏标记。若发生并发写入失败，命令会保留标记并返回失败，避免误用不完整索引。大型论坛应在维护窗口运行，以避免长事务影响正常写入。

重建开始前会再次验证当前 MySQL/MariaDB FULLTEXT 参数。若数据库重启后的配置不再兼容，命令会在清空派生文档前退出，并保留索引脏标记。

## 测试

```bash
composer install
composer test:unit

# 真实数据库 contract test 由 CI 在隔离库中执行；手工执行时必须提供
# ENHANCED_SEARCH_DB_* 与 ENHANCED_SEARCH_EXPECTED_DATABASE=mysql|mariadb
composer test:database

# 以下命令从安装了本扩展的 Flarum 根目录运行
php -d error_reporting=22527 extensions/enhanced-search/tests/boot-smoke.php
# 扩展尚未启用时可改用：tests/boot-smoke.php --manual
# 仅在一次性测试库中启用扩展后运行；未启用会以状态码 2 失败
php -d error_reporting=22527 extensions/enhanced-search/tests/integration.php
```

单元测试覆盖 NFKC/CJK、无碰撞 bigram 编码、LIKE 转义、Unicode 编辑距离、无共同 bigram 的相邻颠倒、短词/数字边界、查询上限和相关性层级。数据库 contract test 会在随机命名的临时表上验证 MySQL/MariaDB FULLTEXT 召回、Boolean phrase 和 `EXPLAIN type=fulltext`，随后精确删除临时表。boot smoke test 只读启动 Flarum，验证依赖注入、Gambit 和控制台命令注册；integration test 会建立带唯一标识的临时讨论，在提交后验证全文召回与 `mostRelevantPost`，最后按精确 ID 清理测试数据。GitHub Actions 还会在 MySQL 8.4、MariaDB 10.11 和 MariaDB 11.8 上全新安装 Flarum 1.8、执行迁移和重建，再运行上述集成测试。

## 设计参考

- [Flarum 1.x 扩展打包与 Composer path repository](https://docs.flarum.org/extend/start/)
- [Flarum `SimpleFlarumSearch` API](https://api.docs.flarum.org/php/v1.3.1/flarum/extend/simpleflarumsearch)
- [Flarum 社区对 Scout Search 的实践与讨论](https://discuss.flarum.org/d/30874-scout-search)
- [MySQL ngram FULLTEXT parser（CJK）](https://dev.mysql.com/doc/refman/8.4/en/fulltext-search-ngram.html)
- [MySQL 默认全文分词与短词限制](https://dev.mysql.com/doc/refman/8.4/en/fulltext-natural-language.html)
- [MariaDB 与 MySQL 8 的 FULLTEXT parser 差异](https://mariadb.com/docs/release-notes/community-server/about/compatibility-and-differences/incompatibilities-and-feature-differences-between-mariadb-10-11-and-mysql-8)
- [MariaDB InnoDB FULLTEXT 概览](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/full-text-indexes/full-text-index-overview)
- [MariaDB `innodb_ft_min_token_size` / `innodb_ft_max_token_size`](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-system-variables)
- [Meilisearch 的动态错字阈值与精确结果优先](https://www.meilisearch.com/docs/capabilities/full_text_search/relevancy/typo_tolerance_settings)
- [Elasticsearch fuzzy query 与编辑距离扩展限制](https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-fuzzy-query.html)
- [PostgreSQL `pg_trgm` 的 n-gram 候选召回](https://www.postgresql.org/docs/17/pgtrgm.html)

这些系统共同采用“索引召回有限候选，再进行相关性/编辑距离排序”的模式。本扩展将该模式做成无需外部服务的 Flarum 1.8/MySQL/MariaDB 实现。
