-- RedefineTables
PRAGMA defer_foreign_keys=ON;
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_DoctorProfile" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "userId" TEXT NOT NULL,
    "specialty" TEXT NOT NULL,
    "bio" TEXT NOT NULL DEFAULT '',
    "avatarUrl" TEXT,
    "sessionPrice" INTEGER NOT NULL DEFAULT 3000000,
    "isApproved" BOOLEAN NOT NULL DEFAULT false,
    "isActive" BOOLEAN NOT NULL DEFAULT false,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "DoctorProfile_userId_fkey" FOREIGN KEY ("userId") REFERENCES "User" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);
INSERT INTO "new_DoctorProfile" ("avatarUrl", "bio", "createdAt", "id", "isActive", "sessionPrice", "specialty", "updatedAt", "userId") SELECT "avatarUrl", "bio", "createdAt", "id", "isActive", "sessionPrice", "specialty", "updatedAt", "userId" FROM "DoctorProfile";
DROP TABLE "DoctorProfile";
ALTER TABLE "new_DoctorProfile" RENAME TO "DoctorProfile";
CREATE UNIQUE INDEX "DoctorProfile_userId_key" ON "DoctorProfile"("userId");
PRAGMA foreign_keys=ON;
PRAGMA defer_foreign_keys=OFF;
