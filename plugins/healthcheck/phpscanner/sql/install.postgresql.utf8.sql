CREATE TABLE IF NOT EXISTS "#__phpscanner_baseline" (
    "id" serial NOT NULL,
    "path" varchar(1024) NOT NULL,
    "hash" char(64) NOT NULL,
    "created" timestamp without time zone DEFAULT NULL,
    PRIMARY KEY ("id")
);
