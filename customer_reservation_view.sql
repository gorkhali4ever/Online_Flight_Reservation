CREATE OR REPLACE VIEW customer_reservation_view AS
SELECT
    pu.username,

    -- Total reservations
    COUNT(r.flightId) AS total_reservations,

    -- Upcoming reservations (today or later)
    SUM(
      CASE 
        WHEN f.flightDate >= TRUNC(SYSDATE) THEN 1 
        ELSE 0 
      END
    ) AS upcoming_reservations,

    -- Average monthly flights for past 12 months (including current month)
    (
      SUM(
        CASE 
          WHEN f.flightDate >= ADD_MONTHS(TRUNC(SYSDATE, 'MM'), -11)
           AND f.flightDate <  ADD_MONTHS(TRUNC(SYSDATE, 'MM'),  1)
          THEN 1 
          ELSE 0 
        END
      ) / 12
    ) AS avg_monthly_flights,

    -- Diamond Customer Score = sum(grade) / count
    CASE 
      WHEN COUNT(r.seating_grade) = 0 THEN NULL
      ELSE SUM(r.seating_grade) / COUNT(r.seating_grade)
    END AS diamond_score

FROM projectUser pu
LEFT JOIN Reserves r ON pu.username = r.username
LEFT JOIN Flight   f ON f.flightId  = r.flightId
GROUP BY pu.username;
