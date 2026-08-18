class RLDate : Managed {
	
	int m_year, m_month, m_day, m_hour, m_min, m_sec;
	bool valid = true;
	
	static RLDate Init(int year, int month, int day, int hour, int minute, int second) {
		RLDate date = new RLDate();
		date.m_year = year;
		date.m_month = month;
		date.m_day = day;
		date.m_hour = hour;
		date.m_min = minute;
		date.m_sec = second;
		return date;
	}
	
	static RLDate Init(bool utcTime) {
		RLDate date = new RLDate();
		if (utcTime) {
			GetYearMonthDayUTC(date.m_year, date.m_month, date.m_day);
			GetHourMinuteSecondUTC(date.m_hour, date.m_min, date.m_sec);
		} else {
			GetYearMonthDay(date.m_year, date.m_month, date.m_day);
			GetHourMinuteSecond(date.m_hour, date.m_min, date.m_sec);
		}
		return date;
	}
	
	static RLDate Init(string timestamp) { // Format: dd.MM.yyyy HH:mm:ss
		RLDate date = new RLDate();
		date.valid = false;
		int index1 = timestamp.IndexOf(".");
		int index2 = timestamp.IndexOfFrom(index1 + 1, ".");
		int index3 = timestamp.IndexOf(" ");
		
		int index4 = timestamp.IndexOf(":");
		int index5 = timestamp.IndexOfFrom(index4 + 1, ":");
		
		if (index1 == -1 || index2 == -1 || index3 == -1 || index4 == -1 || index5 == -1)
			return date;
		if (index1 >= index2 || index2 >= index3 || index3 >= index4 || index4 >= index5)
			return date;
		
		date.m_day = timestamp.Substring(0, index1 - 0).ToInt();
		date.m_month = timestamp.Substring(index1 + 1, index2 - index1 - 1).ToInt();
		date.m_year = timestamp.Substring(index2 + 1, index3 - index2 - 1).ToInt();
		
		date.m_hour = timestamp.Substring(index3 + 1, index4 - index3 - 1).ToInt();
		date.m_min = timestamp.Substring(index4 + 1, index5 - index4 - 1).ToInt();
		date.m_sec = timestamp.Substring(index5 + 1, timestamp.Length() - index5 - 1).ToInt();
		
		date.valid = true;
		
		return date;
	}
	
	static RLDate Init(int timestamp) {
		RLDate date = new RLDate();
		date.m_year = 1970;
		int nextTimestamp = timestamp;
		while (true) {
			if (IsLeapYear(date.m_year)) {
				nextTimestamp -= 31622400;
			} else {
				nextTimestamp -= 31536000;
			}
			if (nextTimestamp < 0)
				break;
			date.m_year++;
			timestamp = nextTimestamp;
		}
		int monthTime = 0;
		nextTimestamp = 0;
		for (int month_ = 1; month_ <= 12; month_++) {
			date.m_month = month_;
			nextTimestamp = monthTime;
			if (month_ == 1 || month_ == 3 || month_ == 5 || month_ == 7 || month_ == 8 || month_ == 10 || month_ == 12)
				monthTime += 31 * 86400;
			else if (month_ == 2 && IsLeapYear(date.m_year))
				monthTime += 29 * 86400;
			else if (month_ == 2)
				monthTime += 28 * 86400;
			else
				monthTime += 30 * 86400;

			if (monthTime > timestamp) {
				timestamp -= nextTimestamp;
				break;
			}
		}
		date.m_day = timestamp / 86400 + 1;
		timestamp = timestamp % 86400;
		date.m_hour = timestamp / 3600;
		timestamp = timestamp % 3600;
		date.m_min = timestamp / 60;
		timestamp = timestamp % 60;
		date.m_sec = timestamp;

		date.valid = true;
		
		return date;
	}
	
	int GetTimestamp() {
		int timestamp = 0;

		for (int year_ = 1970; year_ < m_year; year_++) {
			if (IsLeapYear(year_)) {
				timestamp += 31622400;
			} else {
				timestamp += 31536000;
			}
		}

		for (int month_ = 1; month_ < m_month; month_++) {
			if (month_ == 1 || month_ == 3 || month_ == 5 || month_ == 7 || month_ == 8 || month_ == 10 || month_ == 12)
				timestamp += 31 * 86400;
			else if (month_ == 2 && IsLeapYear(m_year))
				timestamp += 29 * 86400;
			else if (month_ == 2)
				timestamp += 28 * 86400;
			else
				timestamp += 30 * 86400;
		}

		for (int day_ = 1; day_ < m_day; day_++) {
			timestamp += 86400;
		}

		timestamp += m_hour * 3600;
		timestamp += m_min * 60;
		timestamp += m_sec;
		return timestamp;
	}
	
	string ToFormattedString() {
		string monthStr = "" + m_month;
		if (m_month < 10)
			monthStr = "0" + m_month;
		string dayStr = "" + m_day;
		if (m_day < 10)
			dayStr = "0" + m_day;
		return "" + m_day + "." + m_month + "." + m_year + " " + ToFormattedStringShort();
	}
	
	string ToFormattedStringShort() {
		string hourStr = "" + m_hour;
		if (m_hour < 10)
			hourStr = "0" + m_hour;
		string minStr = "" + m_min;
		if (m_min < 10)
			minStr = "0" + m_min;
		string secStr = "" + m_sec;
		if (m_sec < 10)
			secStr = "0" + m_sec;
		return hourStr + ":" + minStr + ":" + secStr;
	}
	
	string ToDiffString(int leadingZeros = 0, int printed = 0) {
		string outStr = "";
		bool print = false;
		if (m_year > 1970 || print || (printed & 0x20)) {
			if (m_hour < 10 && (leadingZeros & 0x20))
				outStr = outStr + "0";
			outStr = outStr + (m_year - 1970) + "y ";
			print = true;
		}
		int days = m_day;
		for (int month_ = 1; month_ < m_month; month_++) {
			if (month_ == 1 || month_ == 3 || month_ == 5 || month_ == 7 || month_ == 8 || month_ == 10 || month_ == 12)
				days += 31;
			else if (month_ == 2 && IsLeapYear(m_year))
				days += 29;
			else if (month_ == 2)
				days += 28;
			else
				days += 30;
		}
		if (days > 1 || print || (printed & 0x08)) {
			if (m_hour < 10 && (leadingZeros & 0x08))
				outStr = outStr + "0";
			outStr = outStr + (days - 1) + "d ";
			print = true;
		}
		if (m_hour > 0 || print || (printed & 0x04)) {
			if (m_hour < 10 && (leadingZeros & 0x04))
				outStr = outStr + "0";
			outStr = outStr + m_hour + "h ";
			print = true;
		}
		if (m_min > 0 || print || (printed & 0x02)) {
			if (m_min < 10 && (leadingZeros & 0x02))
				outStr = outStr + "0";
			outStr = outStr + m_min + "m ";
		}
		if (m_sec < 10 && (leadingZeros & 0x01))
			outStr = outStr + "0";
		outStr = outStr + m_sec + "s";
		return outStr;
	}
	
    private static bool IsLeapYear(int year) {
        if (year % 400 == 0)
            return true;
        if (year % 100 == 0)
            return false;
        if (year % 4 == 0)
            return true;
        return false;
    }
}
